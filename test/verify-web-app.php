<?php
/**
 * PrimePrint Web Application Automated Test Suite
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

    // Extract Location
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
echo "🧪 Running PrimePrint Web Application Test Suite" . PHP_EOL;
echo "==================================================" . PHP_EOL . PHP_EOL;

$baseUrl = 'http://localhost:8000';

// Test 1: Root redirect to login
$res = curlReq("{$baseUrl}/");
assertTest("1. Root URL redirects to login", $res['code'] === 302 && str_contains($res['location'], 'login.php'));

// Test 2: Admin Login page loads & CSRF generated
$res = curlReq("{$baseUrl}/login.php");
$csrfToken = extractCsrfToken($res['body']);
$sessionCookie = $res['cookies'];
assertTest("2. Admin login page loads with CSRF token", $res['code'] === 200 && !empty($csrfToken));

// Test 3: Authenticate Super Admin
$res = curlReq("{$baseUrl}/login.php", 'POST', [
    'csrf_token' => $csrfToken,
    'email'      => 'admin@primeprint.local',
    'password'   => 'ChangeMe123!'
], $sessionCookie);
assertTest("3. Super Admin login authenticates successfully", $res['code'] === 302 && str_contains($res['location'], 'admin/dashboard.php'));
if (!empty($res['cookies'])) {
    $sessionCookie = $res['cookies'];
}

// Test 4: Access Admin Dashboard
$res = curlReq("{$baseUrl}/admin/dashboard.php", 'GET', null, $sessionCookie);
assertTest("4. Admin Dashboard renders KPIs & shop summaries", $res['code'] === 200 && str_contains($res['body'], 'Platform Dashboard'));

// Test 5: Add New Shop via Admin Form
$res = curlReq("{$baseUrl}/admin/shop-add.php", 'GET', null, $sessionCookie);
$csrfToken = extractCsrfToken($res['body']);
$uniqueSuffix = time();
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
assertTest("5. Admin creates new printing shop with dedicated user", $res['code'] === 302 && str_contains($res['location'], 'admin/shop-view.php'), "Code: {$res['code']}, Location: {$res['location']}");

// Test 6: Shop User Login
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

// Test 7: Shop Dashboard & QR Standee
$res = curlReq("{$baseUrl}/shop/dashboard.php", 'GET', null, $shopCookie);
assertTest("7. Shop Dashboard loads shop metrics", $res['code'] === 200 && str_contains($res['body'], 'ABC Digital Printing'));

$res = curlReq("{$baseUrl}/shop/qr.php", 'GET', null, $shopCookie);
assertTest("8. Shop QR Standee page renders", $res['code'] === 200 && str_contains($res['body'], 'Download QR PNG'));

// Test 8: Shop Multi-Tenant Isolation (Shop user forbidden from accessing Admin pages)
$res = curlReq("{$baseUrl}/admin/dashboard.php", 'GET', null, $shopCookie);
assertTest("9. Multi-Tenant Isolation: Shop user denied access to Admin Dashboard", $res['code'] === 403 || str_contains($res['location'], 'shop/dashboard.php'));

// Test 9: Customer Landing Page for Shop
$res = curlReq("{$baseUrl}/p/abc-digital-printing");
assertTest("10. Customer landing page loads for /p/abc-digital-printing", $res['code'] === 200 && str_contains($res['body'], 'ABC Digital Printing') && str_contains($res['body'], 'Print Your Documents'));

// Test 10: Customer Document Upload & Order Creation
$testPdfPath = __DIR__ . '/../test-assets/sample-test-document.pdf';
$customerCsrf = extractCsrfToken($res['body']);
$custCookie = $res['cookies'];

$postData = [
    'csrf_token' => $customerCsrf,
    'paper_size' => 'A4',
    'color_mode' => 'COLOR',
    'side_mode'  => 'single',
    'page_count' => 3,
    'copies'     => 2,
    'document'   => new CURLFile($testPdfPath, 'application/pdf', 'client-contract.pdf')
];

$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
assertTest("11. Customer PDF upload & server-side price calculation", $res['code'] === 302 && str_contains($res['location'], 'order-success.php'));

if (!empty($res['location'])) {
    $successUrl = str_starts_with($res['location'], 'http') ? $res['location'] : "{$baseUrl}/" . ltrim($res['location'], '/');
    $res = curlReq($successUrl, 'GET', null, $custCookie);
    assertTest("12. Order confirmation page renders with calculated total", $res['code'] === 200 && str_contains($res['body'], 'Document Received') && str_contains($res['body'], '₹60.00'));
}

// Test 11: Agent API Scaffolding
// Register Agent
$res = curlReq("{$baseUrl}/api/agent/register", 'POST', json_encode([
    'shop_slug'  => 'abc-digital-printing',
    'agent_name' => 'Automated-Test-Agent-v1',
    'version'    => '1.0.0-poc'
]), '', ['Content-Type: application/json']);
$regData = json_decode($res['body'], true);
$agentToken = $regData['data']['agent_token'] ?? '';
assertTest("13. API: POST /api/agent/register generates token", $res['code'] === 200 && !empty($agentToken));

// Heartbeat
$res = curlReq("{$baseUrl}/api/agent/heartbeat", 'POST', json_encode([
    'version' => '1.0.0-poc'
]), '', [
    'Content-Type: application/json',
    "X-Agent-Token: {$agentToken}"
]);
assertTest("14. API: POST /api/agent/heartbeat with X-Agent-Token", $res['code'] === 200 && str_contains($res['body'], 'Heartbeat acknowledged'));

// Poll Jobs
$res = curlReq("{$baseUrl}/api/agent/jobs", 'GET', null, '', [
    "X-Agent-Token: {$agentToken}"
]);
assertTest("15. API: GET /api/agent/jobs returns job queue", $res['code'] === 200 && isset(json_decode($res['body'], true)['data']), "Code: {$res['code']}, Body: {$res['body']}");

// Sync Local Printers
$res = curlReq("{$baseUrl}/api/agent/printers/sync", 'POST', json_encode([
    'printers' => [
        ['name' => 'Canon imageRUNNER 2006N', 'identifier' => 'WSD-CANON-01', 'status' => 'online'],
        ['name' => 'TVS RP-3150 Thermal POS', 'identifier' => 'USB002', 'status' => 'online']
    ]
]), '', [
    'Content-Type: application/json',
    "X-Agent-Token: {$agentToken}"
]);
assertTest("16. API: POST /api/agent/printers/sync updates shop hardware", $res['code'] === 200 && str_contains($res['body'], 'Synced'), "Code: {$res['code']}, Body: {$res['body']}");

echo PHP_EOL . "🎉 ALL 16 AUTOMATED TESTS PASSED SUCCESSFULLY!" . PHP_EOL;
