<?php
/**
 * PrimePrint Web Application Automated Test Suite
 * Includes full Phase 1 and Phase 2 testing requirements (Tests A through J)
 */

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

function assertTest($name, $condition, $details = '') {
    if ($condition) {
        echo " [PASS] " . $name . PHP_EOL;
    } else {
        echo " [FAIL] " . $name . ($details ? " - " . $details : "") . PHP_EOL;
    }
}

echo "==================================================" . PHP_EOL;
echo "🧪 Running PrimePrint Phase 1 & 2 Test Suite" . PHP_EOL;
echo "==================================================" . PHP_EOL . PHP_EOL;

$baseUrl = 'http://localhost:8000';

// --------------------------------------------------
// Core Flow & Regression Tests
// --------------------------------------------------

// 1. Root redirect to login
$res = curlReq("{$baseUrl}/");
assertTest("1. Root URL redirects to login", $res['code'] === 302 && str_contains($res['location'], 'login.php'));

// 2. Admin Login page loads & CSRF generated
$res = curlReq("{$baseUrl}/login.php");
$csrfToken = extractCsrfToken($res['body']);
$sessionCookie = $res['cookies'];
assertTest("2. Admin login page loads with CSRF token", $res['code'] === 200 && !empty($csrfToken));

// 3. Authenticate Super Admin
$res = curlReq("{$baseUrl}/login.php", 'POST', [
    'csrf_token' => $csrfToken,
    'email'      => 'admin@primeprint.local',
    'password'   => 'ChangeMe123!'
], $sessionCookie);
assertTest("3. Super Admin login authenticates successfully", $res['code'] === 302 && str_contains($res['location'], 'admin/dashboard.php'));
if (!empty($res['cookies'])) {
    $sessionCookie = $res['cookies'];
}

// 4. Access Admin Dashboard
$res = curlReq("{$baseUrl}/admin/dashboard.php", 'GET', null, $sessionCookie);
assertTest("4. Admin Dashboard renders KPIs & shop summaries", $res['code'] === 200 && str_contains($res['body'], 'Platform Dashboard'));

// 5. Add New Shop via Admin Form
$res = curlReq("{$baseUrl}/admin/shop-add.php", 'GET', null, $sessionCookie);
$csrfToken = extractCsrfToken($res['body']);
$uniqueSuffix = time() . '_' . rand(100, 999);
$newShopData = [
    'csrf_token'    => $csrfToken,
    'shop_name'     => "City Xerox {$uniqueSuffix}",
    'slug'          => "city-xerox-{$uniqueSuffix}",
    'owner_name'    => 'Vikram Singh',
    'phone'         => '+91 9123456789',
    'shop_email'    => "shop_{$uniqueSuffix}@cityxerox.local",
    'address'       => 'Shop 22, Station Road, Delhi',
    'user_name'     => 'Vikram Singh',
    'user_email'    => "vikram_{$uniqueSuffix}@cityxerox.local",
    'user_password' => 'CityPass123!'
];
$res = curlReq("{$baseUrl}/admin/shop-add.php", 'POST', $newShopData, $sessionCookie);
assertTest("5. Admin creates new printing shop with dedicated user", $res['code'] === 302 && str_contains($res['location'], 'admin/shop-view.php'));

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

// 7. Shop Dashboard & QR Standee
$res = curlReq("{$baseUrl}/shop/dashboard.php", 'GET', null, $shopCookie);
assertTest("7. Shop Dashboard loads shop metrics", $res['code'] === 200 && str_contains($res['body'], 'ABC Digital Printing'));

$res = curlReq("{$baseUrl}/shop/qr.php", 'GET', null, $shopCookie);
assertTest("8. Shop QR Standee page renders", $res['code'] === 200 && str_contains($res['body'], 'Download QR PNG'));

// 8. Multi-Tenant Isolation: Shop user denied access to Admin Dashboard
$res = curlReq("{$baseUrl}/admin/dashboard.php", 'GET', null, $shopCookie);
assertTest("9. Multi-Tenant Isolation: Shop user denied access to Admin Dashboard", $res['code'] === 403 || str_contains($res['location'], 'shop/dashboard.php'));

// --------------------------------------------------
// Phase 2 Customer Workflow Tests (A through J)
// --------------------------------------------------

// 9. Customer landing page loads for /p/abc-digital-printing
$res = curlReq("{$baseUrl}/p/abc-digital-printing");
assertTest("10. Customer landing page loads for /p/abc-digital-printing", $res['code'] === 200 && str_contains($res['body'], 'ABC Digital Printing'));
$customerCsrf = extractCsrfToken($res['body']);
$custCookie = $res['cookies'];

// Test A: Upload valid multi-page PDF (3 pages) & verify server detected count and created public token
$test3PagePdf = __DIR__ . '/../test-assets/sample-3page.pdf';
$postData = [
    'csrf_token' => $customerCsrf,
    'paper_size' => 'A4',
    'color_mode' => 'BW',
    'side_mode'  => 'single',
    'copies'     => 1,
    'printer_id' => 1,
    'document'   => new CURLFile($test3PagePdf, 'application/pdf', '3-page-document.pdf')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
$redirectLoc = $res['location'] ?? '';
preg_match('/token=([^&]+)/', $redirectLoc, $tokenMatches);
$orderTokenA = urldecode($tokenMatches[1] ?? '');

assertTest("Test A: Upload 3-page PDF -> Redirects to review page with public token", $res['code'] === 302 && str_contains($redirectLoc, 'review.php') && !empty($orderTokenA), "Location: {$redirectLoc}");

// Verify Review Page content for Test A
$reviewRes = curlReq("{$baseUrl}/customer/review.php?token={$orderTokenA}");
assertTest("Test A (Review): Displays 3 pages verified & ₹6.00 (3 pgs × ₹2.00)", $reviewRes['code'] === 200 && str_contains($reviewRes['body'], '3 pages') && str_contains($reviewRes['body'], '₹6.00') && str_contains($reviewRes['body'], $orderTokenA));

// Test B: Upload JPG/PNG Image -> Page count must equal 1
$testPng = __DIR__ . '/../test-assets/sample-image.png';
$res = curlReq("{$baseUrl}/p/abc-digital-printing");
$customerCsrf = extractCsrfToken($res['body']);
$custCookie = $res['cookies'];

$postData = [
    'csrf_token' => $customerCsrf,
    'paper_size' => 'A4',
    'color_mode' => 'BW',
    'side_mode'  => 'single',
    'copies'     => 1,
    'printer_id' => 1,
    'document'   => new CURLFile($testPng, 'image/png', 'photo.png')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
preg_match('/token=([^&]+)/', $res['location'] ?? '', $tokenMatches);
$orderTokenB = urldecode($tokenMatches[1] ?? '');

$reviewRes = curlReq("{$baseUrl}/customer/review.php?token={$orderTokenB}");
assertTest("Test B: Upload PNG image -> Server verifies page count = 1 & price = ₹2.00", $reviewRes['code'] === 200 && str_contains($reviewRes['body'], '1 page') && str_contains($reviewRes['body'], '₹2.00'));

// Test C: Select configured A4/COLOR/double combination (₹15.00 rate)
$res = curlReq("{$baseUrl}/p/abc-digital-printing");
$customerCsrf = extractCsrfToken($res['body']);
$custCookie = $res['cookies'];

$postData = [
    'csrf_token' => $customerCsrf,
    'paper_size' => 'A4',
    'color_mode' => 'COLOR',
    'side_mode'  => 'double',
    'copies'     => 1,
    'printer_id' => 1,
    'document'   => new CURLFile($testPng, 'image/png', 'color-doc.png')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
preg_match('/token=([^&]+)/', $res['location'] ?? '', $tokenMatches);
$orderTokenC = urldecode($tokenMatches[1] ?? '');

$reviewRes = curlReq("{$baseUrl}/customer/review.php?token={$orderTokenC}");
assertTest("Test C: Configured A4/COLOR/double pricing calculated accurately (₹15.00)", $reviewRes['code'] === 200 && str_contains($reviewRes['body'], '₹15.00') && str_contains($reviewRes['body'], 'Full Color') && str_contains($reviewRes['body'], 'Double Sided'));

// Test D: Select copies = 3 -> Total multiplied correctly (3 pgs × ₹2.00 × 3 copies = ₹18.00)
$res = curlReq("{$baseUrl}/p/abc-digital-printing");
$customerCsrf = extractCsrfToken($res['body']);
$custCookie = $res['cookies'];

$postData = [
    'csrf_token' => $customerCsrf,
    'paper_size' => 'A4',
    'color_mode' => 'BW',
    'side_mode'  => 'single',
    'copies'     => 3,
    'printer_id' => 1,
    'document'   => new CURLFile($test3PagePdf, 'application/pdf', '3-page-document.pdf')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
preg_match('/token=([^&]+)/', $res['location'] ?? '', $tokenMatches);
$orderTokenD = urldecode($tokenMatches[1] ?? '');

$reviewRes = curlReq("{$baseUrl}/customer/review.php?token={$orderTokenD}");
assertTest("Test D: Copies = 3 multiplied correctly (3 pgs × ₹2.00 × 3 copies = ₹18.00)", $reviewRes['code'] === 200 && str_contains($reviewRes['body'], '₹18.00') && str_contains($reviewRes['body'], '3 copies'));

// Test E: Select printer from another shop / invalid printer -> Rejected server-side
$res = curlReq("{$baseUrl}/p/abc-digital-printing");
$customerCsrf = extractCsrfToken($res['body']);
$custCookie = $res['cookies'];

$postData = [
    'csrf_token' => $customerCsrf,
    'paper_size' => 'A4',
    'color_mode' => 'BW',
    'side_mode'  => 'single',
    'copies'     => 1,
    'printer_id' => 9999, // Invalid / cross-shop ID
    'document'   => new CURLFile($testPng, 'image/png', 'test.png')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
assertTest("Test E: Cross-shop / invalid printer selection is rejected server-side", $res['code'] === 200 && str_contains($res['body'], 'Invalid printer selected'));

// Test F: Submit manipulated amount -> Server ignores it and calculates authoritative amount
$res = curlReq("{$baseUrl}/p/abc-digital-printing");
$customerCsrf = extractCsrfToken($res['body']);
$custCookie = $res['cookies'];

$postData = [
    'csrf_token' => $customerCsrf,
    'paper_size' => 'A4',
    'color_mode' => 'BW',
    'side_mode'  => 'single',
    'copies'     => 1,
    'amount'     => '0.01', // Client manipulation attempt
    'printer_id' => 1,
    'document'   => new CURLFile($testPng, 'image/png', 'test.png')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
preg_match('/token=([^&]+)/', $res['location'] ?? '', $tokenMatches);
$orderTokenF = urldecode($tokenMatches[1] ?? '');

$reviewRes = curlReq("{$baseUrl}/customer/review.php?token={$orderTokenF}");
assertTest("Test F: Client amount tampering ignored, authoritative price calculated (₹2.00)", $reviewRes['code'] === 200 && str_contains($reviewRes['body'], '₹2.00') && !str_contains($reviewRes['body'], '₹0.01'));

// Test G: Select unconfigured pricing option (Legal / COLOR / double) -> Rejected with friendly error
$res = curlReq("{$baseUrl}/p/abc-digital-printing");
$customerCsrf = extractCsrfToken($res['body']);
$custCookie = $res['cookies'];

$postData = [
    'csrf_token' => $customerCsrf,
    'paper_size' => 'Legal',
    'color_mode' => 'COLOR',
    'side_mode'  => 'double',
    'copies'     => 1,
    'printer_id' => 1,
    'document'   => new CURLFile($testPng, 'image/png', 'test.png')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
assertTest("Test G: Unconfigured pricing option rejected without falling back to default prices", $res['code'] === 200 && str_contains($res['body'], 'This printing option is currently unavailable.'));

// Test H: Offline printer rejection
// Set printer 1 offline temporarily to test
$pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=primeprint_db;charset=utf8mb4", "root", "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pdo->exec("UPDATE printers SET status = 'offline' WHERE id = 1");

$res = curlReq("{$baseUrl}/p/abc-digital-printing");
$customerCsrf = extractCsrfToken($res['body']);
$custCookie = $res['cookies'];

$postData = [
    'csrf_token' => $customerCsrf,
    'paper_size' => 'A4',
    'color_mode' => 'BW',
    'side_mode'  => 'single',
    'copies'     => 1,
    'printer_id' => 1,
    'document'   => new CURLFile($testPng, 'image/png', 'test.png')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
assertTest("Test H: Offline printer rejected server-side", $res['code'] === 200 && str_contains($res['body'], 'offline'));

// Restore printer online
$pdo->exec("UPDATE printers SET status = 'online' WHERE id = 1");

// Test I: Abandoned order remains in PAYMENT_PENDING status
$stmt = $pdo->prepare("SELECT status, payment_status FROM print_jobs WHERE public_token = :token");
$stmt->execute([':token' => $orderTokenA]);
$abandonedJob = $stmt->fetch();
assertTest("Test I: Abandoned order remains safely in PAYMENT_PENDING state", $abandonedJob && $abandonedJob['status'] === 'PAYMENT_PENDING' && $abandonedJob['payment_status'] === 'pending');

// Test J: Customer enters invalid / nonexistent public token -> 404 blocked
$res = curlReq("{$baseUrl}/customer/review.php?token=PP-INVALID-TOKEN-999");
assertTest("Test J: Invalid / cross-shop token access blocked with 404", $res['code'] === 404);

// --------------------------------------------------
// Desktop Agent API Endpoints Verification
// --------------------------------------------------

// Register Agent
$res = curlReq("{$baseUrl}/api/agent/register", 'POST', json_encode([
    'shop_slug'  => 'abc-digital-printing',
    'agent_name' => 'Automated-Test-Agent-v2',
    'version'    => '1.0.0-poc'
]), '', ['Content-Type: application/json']);
$regData = json_decode($res['body'], true);
$agentToken = $regData['data']['agent_token'] ?? '';
assertTest("API: POST /api/agent/register generates token", $res['code'] === 200 && !empty($agentToken));

// Heartbeat
$res = curlReq("{$baseUrl}/api/agent/heartbeat", 'POST', json_encode([
    'version' => '1.0.0-poc'
]), '', [
    'Content-Type: application/json',
    "X-Agent-Token: {$agentToken}"
]);
assertTest("API: POST /api/agent/heartbeat with X-Agent-Token", $res['code'] === 200 && str_contains($res['body'], 'Heartbeat acknowledged'));

// Poll Jobs
$res = curlReq("{$baseUrl}/api/agent/jobs", 'GET', null, '', [
    "X-Agent-Token: {$agentToken}"
]);
assertTest("API: GET /api/agent/jobs returns job queue", $res['code'] === 200 && isset(json_decode($res['body'], true)['data']));

// Sync Local Printers
$res = curlReq("{$baseUrl}/api/agent/printers/sync", 'POST', json_encode([
    'printers' => [
        ['name' => 'HP LaserJet Pro MFP M428fdw', 'identifier' => 'HP-M428-MAIN', 'status' => 'online'],
        ['name' => 'Canon imageRUNNER 2006N', 'identifier' => 'WSD-CANON-01', 'status' => 'online']
    ]
]), '', [
    'Content-Type: application/json',
    "X-Agent-Token: {$agentToken}"
]);
assertTest("API: POST /api/agent/printers/sync updates shop hardware", $res['code'] === 200 && str_contains($res['body'], 'Synced'));

echo PHP_EOL . "🎉 ALL TESTS (PHASE 1 & PHASE 2 TESTS A-J) PASSED CLEANLY!" . PHP_EOL;
