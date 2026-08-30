<?php
/**
 * PrimePrint API — Instant 1-Click Customer Upload & Razorpay Order Creation
 * Endpoint: POST /api/customer/quick-checkout.php
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/csrf.php';
require_once __DIR__ . '/../../config/helpers.php';
require_once __DIR__ . '/../../config/razorpay.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method Not Allowed. POST required.']);
    exit;
}

try {
    $db = getDBConnection();

    // 1. Verify CSRF Token
    $csrfToken = trim($_POST['csrf_token'] ?? '');
    if (empty($csrfToken) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $csrfToken)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'Security session expired. Please refresh the page.']);
        exit;
    }

    // 2. Identify Target Shop
    $shopSlug = trim($_POST['shop_slug'] ?? '');
    if (empty($shopSlug)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Shop slug is required.']);
        exit;
    }

    $stmt = $db->prepare("
        SELECT id, name, slug, status, plan_id, plan_name, subscription_status, subscription_expires_at,
               razorpay_key_id, razorpay_key_secret
        FROM shops 
        WHERE slug = :slug 
        LIMIT 1
    ");
    $stmt->execute([':slug' => $shopSlug]);
    $shop = $stmt->fetch();

    if (!$shop || $shop['status'] !== 'active') {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => 'Print shop not found or is currently inactive.']);
        exit;
    }

    // 3. Verify Shop SaaS License Status
    $subStatus = $shop['subscription_status'] ?? 'active';
    $expiresAt = $shop['subscription_expires_at'];

    $isLicenseExpired = false;
    if ($subStatus === 'expired') {
        $isLicenseExpired = true;
    } elseif (!empty($expiresAt) && strtotime($expiresAt) < time()) {
        $isLicenseExpired = true;
    }


    if ($isLicenseExpired) {
        http_response_code(403);
        echo json_encode([
            'success' => false, 
            'error'   => 'This printing shop is currently undergoing scheduled maintenance. Please check with the counter staff.'
        ]);
        exit;
    }

    // 4. Validate Print Options
    $paperSize = trim($_POST['paper_size'] ?? 'A4');
    $colorMode = trim($_POST['color_mode'] ?? 'BW');
    $sideMode  = trim($_POST['side_mode'] ?? 'single');
    $copies    = (int)($_POST['copies'] ?? 1);

    if ($copies < 1 || $copies > 100) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Copies must be between 1 and 100.']);
        exit;
    }

    // 5. Validate and Store Document
    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Please select a valid document to upload.']);
        exit;
    }

    $file = $_FILES['document'];
    $originalName = basename($file['name']);
    $fileSize = (int)$file['size'];
    $tmpPath = $file['tmp_name'];

    if ($fileSize <= 0 || $fileSize > MAX_FILE_SIZE_BYTES) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'File size exceeds maximum limit of 25 MB.']);
        exit;
    }

    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Only PDF, JPG, JPEG, and PNG files are supported.']);
        exit;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    if (!in_array($mimeType, ALLOWED_MIME_TYPES, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => "Invalid file format ({$mimeType})."]);
        exit;
    }

    $storedFileName = bin2hex(random_bytes(16)) . '.' . $ext;
    $destination = UPLOAD_DIR . $storedFileName;

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    if (!move_uploaded_file($tmpPath, $destination)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Failed to save document. Please try again.']);
        exit;
    }

    // 6. Determine Page Count
    if ($ext === 'pdf' || str_contains($mimeType, 'pdf')) {
        $detectedPages = detect_pdf_page_count($destination);
        if ($detectedPages === false || $detectedPages <= 0) {
            @unlink($destination);
            http_response_code(422);
            echo json_encode(['success' => false, 'error' => 'Unable to read PDF page count. Ensure file is not password-protected.']);
            exit;
        }
        $pageCount = $detectedPages;
    } else {
        $pageCount = 1;
    }

    // 7. Calculate Verified Order Price
    $priceResult = calculate_order_price($db, $shop['id'], $paperSize, $colorMode, $sideMode, $pageCount, $copies);
    if (!$priceResult['success']) {
        @unlink($destination);
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => $priceResult['error']]);
        exit;
    }

    $totalAmount = $priceResult['total_amount'];
    $amountPaise = (int)round($totalAmount * 100);

    // 8. Auto-assign Primary Shop Printer
    $stmt = $db->prepare("
        SELECT id FROM printers 
        WHERE shop_id = :shop_id 
        ORDER BY (status IN ('online', 'idle')) DESC, id ASC 
        LIMIT 1
    ");
    $stmt->execute([':shop_id' => $shop['id']]);
    $autoPrinter = $stmt->fetch();
    $printerId = $autoPrinter ? (int)$autoPrinter['id'] : null;

    // 9. Insert Print Job Record
    $publicToken = generate_public_order_token($db);

    $stmt = $db->prepare("
        INSERT INTO print_jobs (
            public_token, shop_id, printer_id, file_name, stored_file_name, file_path, file_type, 
            page_count, copies, paper_size, color_mode, side_mode, 
            amount, status, payment_status
        ) VALUES (
            :public_token, :shop_id, :printer_id, :file_name, :stored_file_name, :file_path, :file_type, 
            :page_count, :copies, :paper_size, :color_mode, :side_mode, 
            :amount, 'PAYMENT_PENDING', 'pending'
        )
    ");
    $stmt->execute([
        ':public_token'     => $publicToken,
        ':shop_id'          => $shop['id'],
        ':printer_id'       => $printerId,
        ':file_name'        => $originalName,
        ':stored_file_name' => $storedFileName,
        ':file_path'        => $destination,
        ':file_type'        => $mimeType,
        ':page_count'       => $pageCount,
        ':copies'           => $copies,
        ':paper_size'       => $paperSize,
        ':color_mode'       => $colorMode,
        ':side_mode'        => $sideMode,
        ':amount'           => $totalAmount
    ]);
    $jobId = (int)$db->lastInsertId();

    // 10. Immediately Create Razorpay Order
    $receiptId = 'rcpt_job_' . $jobId . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $notes = [
        'job_id'       => (string)$jobId,
        'public_token' => $publicToken,
        'shop_id'      => (string)$shop['id'],
        'shop_name'    => $shop['name'],
        'pages'        => (string)$pageCount,
        'copies'       => (string)$copies
    ];

    $customShopKeyId = !empty($shop['razorpay_key_id']) ? trim($shop['razorpay_key_id']) : null;
    $customShopKeySecret = !empty($shop['razorpay_key_secret']) ? trim($shop['razorpay_key_secret']) : null;

    $rzpOrder = razorpay_create_order($amountPaise, $receiptId, $notes, $customShopKeyId, $customShopKeySecret);

    if (!$rzpOrder || empty($rzpOrder['order_id'])) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error'   => 'Payment gateway initialization failed. Please contact the counter.'
        ]);
        exit;
    }

    $activeKeyId = !empty($customShopKeyId) ? $customShopKeyId : RAZORPAY_KEY_ID;

    // 11. Record in Payments Table
    $stmt = $db->prepare("
        INSERT INTO payments (
            job_id, shop_id, razorpay_order_id, amount, status, created_at, updated_at
        ) VALUES (
            :job_id, :shop_id, :order_id, :amount, 'created', NOW(), NOW()
        )
    ");
    $stmt->execute([
        ':job_id'   => $jobId,
        ':shop_id'  => $shop['id'],
        ':order_id' => $rzpOrder['order_id'],
        ':amount'   => $totalAmount
    ]);


    // 12. Return Instant Checkout Payload
    echo json_encode([
        'success'          => true,
        'token'            => $publicToken,
        'job_id'           => $jobId,
        'order_id'         => $rzpOrder['order_id'],
        'key_id'           => $activeKeyId,
        'amount'           => $amountPaise,
        'total_rupees'     => $totalAmount,
        'formatted_amount' => format_currency($totalAmount),
        'currency'         => 'INR',
        'shop_name'        => $shop['name'],
        'page_count'       => $pageCount,
        'copies'           => $copies
    ]);

} catch (Exception $e) {
    error_log("Quick checkout error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error'   => 'An unexpected error occurred while preparing your print order.'
    ]);
}
