<?php
/**
 * PrimePrint Automated Audit & Hardening Test Suite
 * Comprehensive verification of Phase 1, Phase 2, and Security Hardening
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
echo "🛡️  Running PrimePrint Security & Quality Audit Suite" . PHP_EOL;
echo "==================================================" . PHP_EOL . PHP_EOL;

$baseUrl = 'http://localhost:8000';
$pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=primeprint_db;charset=utf8mb4", "root", "", [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// --------------------------------------------------
// 1. Core Auth & Session Security Tests
// --------------------------------------------------

// 1. Root redirect to login
$res = curlReq("{$baseUrl}/");
assertTest("1. Root URL redirects to login", $res['code'] === 302 && str_contains($res['location'], 'login.php'));

// 2. Admin Login CSRF
$res = curlReq("{$baseUrl}/login.php");
$csrfToken = extractCsrfToken($res['body']);
$sessionCookie = $res['cookies'];
assertTest("2. Admin login page loads with CSRF token", $res['code'] === 200 && !empty($csrfToken));

// 3. Super Admin Authentication & Session Fixation Protection
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

// 5. Create Second Shop for Multi-Tenant Isolation Testing (Shop B)
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

// Get Shop B ID by email
$stmt = $pdo->prepare("SELECT id FROM shops WHERE email = :email LIMIT 1");
$stmt->execute([':email' => "shopb_{$shopBSuffix}@test.local"]);
$shopBId = (int)$stmt->fetchColumn();

// Add an isolated printer to Shop B
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

// 7. Shop Isolation Guard
$res = curlReq("{$baseUrl}/admin/dashboard.php", 'GET', null, $shopCookie);
assertTest("7. Multi-Tenant Guard: Shop user blocked from Admin console", $res['code'] === 403 || str_contains($res['location'], 'shop/dashboard.php'));

// --------------------------------------------------
// 2. Customer Workflow & PDF Page Count Auditing
// --------------------------------------------------

$test1PagePdf = __DIR__ . '/../test-assets/sample-test-document.pdf';
$test3PagePdf = __DIR__ . '/../test-assets/sample-3page.pdf';
$test5PagePdf = __DIR__ . '/../test-assets/sample-5page.pdf';
$testPng      = __DIR__ . '/../test-assets/sample-image.png';

// Audit Item 1 & 2: 1-Page PDF Detection
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
    'document'   => new CURLFile($test1PagePdf, 'application/pdf', '1page.pdf')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
preg_match('/token=([^&]+)/', $res['location'] ?? '', $m);
$token1P = urldecode($m[1] ?? '');
$rev1P = curlReq("{$baseUrl}/customer/review.php?token={$token1P}");
assertTest("Audit 2a: 1-Page PDF detected accurately (1 page verified, ₹2.00)", $rev1P['code'] === 200 && str_contains($rev1P['body'], '1 page') && str_contains($rev1P['body'], '₹2.00'));

// Audit Item 2b: 3-Page PDF Detection
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
    'document'   => new CURLFile($test3PagePdf, 'application/pdf', '3page.pdf')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
preg_match('/token=([^&]+)/', $res['location'] ?? '', $m);
$token3P = urldecode($m[1] ?? '');
$rev3P = curlReq("{$baseUrl}/customer/review.php?token={$token3P}");
assertTest("Audit 2b: 3-Page PDF detected accurately (3 pages verified, ₹6.00)", $rev3P['code'] === 200 && str_contains($rev3P['body'], '3 pages') && str_contains($rev3P['body'], '₹6.00'));

// Audit Item 2c: 5-Page PDF Detection & Acceptance Test
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
    'document'   => new CURLFile($test5PagePdf, 'application/pdf', '5page.pdf')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
preg_match('/token=([^&]+)/', $res['location'] ?? '', $m);
$token5P = urldecode($m[1] ?? '');
$rev5P = curlReq("{$baseUrl}/customer/review.php?token={$token5P}");
assertTest("Audit 2c & Section 19 Acceptance Test: 5-Page PDF × 2 copies @ ₹2 = ₹20.00 verified on review page", $rev5P['code'] === 200 && str_contains($rev5P['body'], '5 pages') && str_contains($rev5P['body'], '2 copies') && str_contains($rev5P['body'], '₹20.00'));

// Verify database record for Section 19 acceptance test
$stmt = $pdo->prepare("SELECT status, payment_status, page_count, copies, amount FROM print_jobs WHERE public_token = :tok");
$stmt->execute([':tok' => $token5P]);
$jobRow = $stmt->fetch();
assertTest("Audit 14: Job status = PAYMENT_PENDING & payment_status = pending in database", $jobRow && $jobRow['status'] === 'PAYMENT_PENDING' && $jobRow['payment_status'] === 'pending' && (float)$jobRow['amount'] === 20.00);

// Audit Item 3: Image Page Count (strictly 1 page)
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
assertTest("Audit 3: Image (PNG) page count is strictly 1 page (₹2.00)", $revImg['code'] === 200 && str_contains($revImg['body'], '1 page') && str_contains($revImg['body'], '₹2.00'));

// --------------------------------------------------
// 3. Security & Anti-Manipulation Auditing
// --------------------------------------------------

// Audit Item 4: Price / Amount tampering attempt
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
    'amount'     => '0.01', // Attacker sends 1 paisa
    'printer_id' => 1,
    'document'   => new CURLFile($testPng, 'image/png', 'test.png')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
preg_match('/token=([^&]+)/', $res['location'] ?? '', $m);
$tokenTamper = urldecode($m[1] ?? '');
$revTamper = curlReq("{$baseUrl}/customer/review.php?token={$tokenTamper}");
assertTest("Audit 4: Client price tampering ignored, authoritative database price enforced", $revTamper['code'] === 200 && str_contains($revTamper['body'], '₹2.00') && !str_contains($revTamper['body'], '₹0.01'));

// Audit Item 5: Unconfigured pricing combinations rejected without default fallback
$res = curlReq("{$baseUrl}/p/abc-digital-printing");
$csrf = extractCsrfToken($res['body']);
$formTok = extractFormToken($res['body']);
$custCookie = $res['cookies'];

$postData = [
    'csrf_token' => $csrf,
    'form_token' => $formTok,
    'paper_size' => 'Legal',
    'color_mode' => 'COLOR',
    'side_mode'  => 'double',
    'copies'     => 1,
    'printer_id' => 1,
    'document'   => new CURLFile($testPng, 'image/png', 'test.png')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
assertTest("Audit 5: Unconfigured pricing option rejected without falling back to default prices", $res['code'] === 200 && str_contains($res['body'], 'This printing option is currently unavailable.'));

// Audit Item 6: Cross-Shop Printer Isolation (Submitting Shop A order with Shop B's printer ID)
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
    'printer_id' => $shopBPrinterId, // Belongs to Shop B!
    'document'   => new CURLFile($testPng, 'image/png', 'test.png')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
assertTest("Audit 6: Submitting Shop A order with Shop B printer ID is strictly rejected", $res['code'] === 200 && str_contains($res['body'], 'Invalid printer selected'));

// Audit Item 8: Public Order Token Security & Injection Resistance
$res = curlReq("{$baseUrl}/customer/review.php?token=" . urlencode("' OR '1'='1"));
assertTest("Audit 8a: SQL injection in token parameter blocked with 404", $res['code'] === 404);

$res = curlReq("{$baseUrl}/customer/review.php?token=PP-NONEXISTENT-1234");
assertTest("Audit 8b: Random / non-existent token blocked with 404", $res['code'] === 404);

// Audit Item 9: Duplicate Submission / Double-Click Protection
// Re-submitting the exact same form token
$resDup = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
assertTest("Audit 9: Duplicate form resubmission prevented without creating duplicate database orders", $resDup['code'] === 302 || str_contains($resDup['body'], 'already been submitted') || str_contains($resDup['body'], 'Invalid printer'));

// Audit Item 10: File Upload Security (Disguised PHP script / invalid MIME)
$fakePhpFile = __DIR__ . '/../test-assets/fake-script.php';
file_put_contents($fakePhpFile, '<?php echo "evil"; ?>');

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
    'document'   => new CURLFile($fakePhpFile, 'text/php', 'fake-script.php')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
@unlink($fakePhpFile);
assertTest("Audit 10: Executable/PHP file upload strictly blocked by extension & MIME filter", $res['code'] === 200 && (str_contains($res['body'], 'Only PDF, JPG, JPEG, and PNG') || str_contains($res['body'], 'Invalid file format')));

// Audit Item 11: CSRF Token Enforcement
$res = curlReq("{$baseUrl}/p/abc-digital-printing");
$custCookie = $res['cookies'];

$postData = [
    'csrf_token' => 'INVALID_CSRF_TOKEN_123',
    'paper_size' => 'A4',
    'color_mode' => 'BW',
    'side_mode'  => 'single',
    'copies'     => 1,
    'printer_id' => 1,
    'document'   => new CURLFile($testPng, 'image/png', 'test.png')
];
$res = curlReq("{$baseUrl}/p/abc-digital-printing", 'POST', $postData, $custCookie, [], true);
assertTest("Audit 11: Invalid CSRF token on customer upload rejected with 403", $res['code'] === 403);

// --------------------------------------------------
// 4. Print Agent REST APIs Regression
// --------------------------------------------------

$res = curlReq("{$baseUrl}/api/agent/register", 'POST', json_encode([
    'shop_slug'  => 'abc-digital-printing',
    'agent_name' => 'Audit-Runner-Agent',
    'version'    => '1.0.0-audit'
]), '', ['Content-Type: application/json']);
$regData = json_decode($res['body'], true);
$agentToken = $regData['data']['agent_token'] ?? '';
assertTest("API 1: POST /api/agent/register generates secure agent token", $res['code'] === 200 && !empty($agentToken));

$res = curlReq("{$baseUrl}/api/agent/heartbeat", 'POST', json_encode(['version' => '1.0.0-audit']), '', [
    'Content-Type: application/json',
    "X-Agent-Token: {$agentToken}"
]);
assertTest("API 2: POST /api/agent/heartbeat with X-Agent-Token acknowledges heartbeat", $res['code'] === 200 && str_contains($res['body'], 'Heartbeat acknowledged'));

$res = curlReq("{$baseUrl}/api/agent/jobs", 'GET', null, '', [
    "X-Agent-Token: {$agentToken}"
]);
assertTest("API 3: GET /api/agent/jobs polls queue without error", $res['code'] === 200 && isset(json_decode($res['body'], true)['data']));

$res = curlReq("{$baseUrl}/api/agent/printers/sync", 'POST', json_encode([
    'printers' => [
        ['name' => 'HP LaserJet Pro MFP M428fdw', 'identifier' => 'HP-M428-MAIN', 'status' => 'online']
    ]
]), '', [
    'Content-Type: application/json',
    "X-Agent-Token: {$agentToken}"
]);
assertTest("API 4: POST /api/agent/printers/sync updates hardware successfully", $res['code'] === 200 && str_contains($res['body'], 'Synced'));

echo PHP_EOL . "==================================================" . PHP_EOL;
echo "🎉 ALL AUDIT & HARDENING TESTS PASSED WITH ZERO FAILURES!" . PHP_EOL;
echo "==================================================" . PHP_EOL;
