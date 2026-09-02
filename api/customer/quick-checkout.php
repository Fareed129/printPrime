<?php
/**
 * PrimePrint API — Instant Customer Upload & Checkout (V2)
 * Endpoint: POST /api/customer/quick-checkout.php
 * Supports: Multi-file upload, PDF page selection, ID Card mode, Online (Razorpay) & Cash payments.
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
        echo json_encode(['success' => false, 'error' => 'Security session expired. Please refresh the page and try again.']);
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

    // 4. Validate Print Options & Strictly Reject A3
    $requestedPaperSize = strtoupper(trim($_POST['paper_size'] ?? 'A4'));
    if ($requestedPaperSize === 'A3') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'A3 printing is not supported. Please choose A4.']);
        exit;
    }

    $paperSize     = 'A4';
    $sideMode      = 'single'; // Customer portal is strictly single-sided
    $colorMode     = (strtoupper(trim($_POST['color_mode'] ?? 'BW')) === 'COLOR') ? 'COLOR' : 'BW';
    $copies        = (int)($_POST['copies'] ?? 1);
    $paymentMethod = (strtoupper(trim($_POST['payment_method'] ?? 'ONLINE')) === 'CASH') ? 'CASH' : 'ONLINE';
    $printMode     = trim($_POST['print_mode'] ?? 'regular'); // 'regular' or 'id_card'

    if ($copies < 1 || $copies > 100) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Copies must be between 1 and 100.']);
        exit;
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    $publicToken = generate_public_order_token($db);
    $primaryFileName = 'document.pdf';
    $primaryStoredName = '';
    $primaryFilePath = '';
    $primaryMimeType = 'application/pdf';
    $totalPageCount = 0;
    $isIdCard = 0;
    $selectedPagesSummary = null;
    $orderFiles = []; // array of child files for print_job_files table

    // ==========================================
    // CASE A: ID CARD / AADHAAR PRINT WORKFLOW
    // ==========================================
    if ($printMode === 'id_card') {
        $isIdCard = 1;

        // Helper to save uploaded or base64 image
        $saveCardImage = function($fileKey, $base64Key, $suffix) use ($publicToken) {
            $tmpDest = UPLOAD_DIR . 'temp_id_' . $suffix . '_' . bin2hex(random_bytes(6)) . '.jpg';
            
            // Check file upload first
            if (isset($_FILES[$fileKey]) && $_FILES[$fileKey]['error'] === UPLOAD_ERR_OK) {
                $f = $_FILES[$fileKey];
                if ($f['size'] <= 0 || $f['size'] > MAX_FILE_SIZE_BYTES) return false;
                if (!move_uploaded_file($f['tmp_name'], $tmpDest)) return false;
            } elseif (!empty($_POST[$base64Key])) {
                // Decode base64 data URL
                $raw = $_POST[$base64Key];
                if (preg_match('/^data:image\/(\w+);base64,/', $raw, $type)) {
                    $raw = substr($raw, strpos($raw, ',') + 1);
                }
                $decoded = base64_decode($raw);
                if (!$decoded || strlen($decoded) === 0 || strlen($decoded) > MAX_FILE_SIZE_BYTES) return false;
                file_put_contents($tmpDest, $decoded);
            } else {
                return false;
            }

            // Validate image readability
            $info = @getimagesize($tmpDest);
            if (!$info || !in_array($info['mime'], ALLOWED_MIME_TYPES, true)) {
                @unlink($tmpDest);
                return false;
            }
            return $tmpDest;
        };

        $frontTemp = $saveCardImage('id_front', 'id_front_data', 'front');
        $backTemp  = $saveCardImage('id_back', 'id_back_data', 'back');

        if (!$frontTemp || !$backTemp) {
            if ($frontTemp) @unlink($frontTemp);
            if ($backTemp) @unlink($backTemp);
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please provide valid front and back images of your ID card.']);
            exit;
        }

        // Generate A4 composed PDF
        $primaryStoredName = 'idcard_' . bin2hex(random_bytes(12)) . '.pdf';
        $primaryFilePath   = UPLOAD_DIR . $primaryStoredName;
        $primaryFileName   = 'ID_Card_A4.pdf';
        $primaryMimeType   = 'application/pdf';
        $totalPageCount    = 1; // 1 composed A4 page

        $genOk = generate_id_card_a4_pdf($frontTemp, $backTemp, $primaryFilePath);

        // Delete temporary cropped source images now that A4 document is generated
        @unlink($frontTemp);
        @unlink($backTemp);

        if (!$genOk || !file_exists($primaryFilePath)) {
            http_response_code(500);
            echo json_encode(['success' => false, 'error' => 'Failed to compose ID card onto A4 page. Please try again.']);
            exit;
        }

        $orderFiles[] = [
            'original_name' => 'ID_Card_Front_Back.pdf',
            'stored_name'   => $primaryStoredName,
            'file_path'     => $primaryFilePath,
            'file_type'     => 'application/pdf',
            'file_size'     => filesize($primaryFilePath),
            'page_count'    => 1,
            'selected_pages'=> '1',
            'sort_order'    => 1
        ];

    // ==========================================
    // CASE B: REGULAR PRINT (DOCUMENTS & PHOTOS)
    // ==========================================
    } else {
        // Collect uploaded files (either multiple 'documents[]' or single 'document')
        $rawFiles = [];
        if (isset($_FILES['documents']) && is_array($_FILES['documents']['name'])) {
            $numFiles = count($_FILES['documents']['name']);
            for ($i = 0; $i < $numFiles; $i++) {
                if ($_FILES['documents']['error'][$i] === UPLOAD_ERR_NO_FILE) continue;
                $rawFiles[] = [
                    'name'     => $_FILES['documents']['name'][$i],
                    'type'     => $_FILES['documents']['type'][$i],
                    'tmp_name' => $_FILES['documents']['tmp_name'][$i],
                    'error'    => $_FILES['documents']['error'][$i],
                    'size'     => $_FILES['documents']['size'][$i]
                ];
            }
        } elseif (isset($_FILES['document']) && $_FILES['document']['error'] !== UPLOAD_ERR_NO_FILE) {
            $rawFiles[] = $_FILES['document'];
        }

        if (empty($rawFiles)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'Please select at least one document or photo to print.']);
            exit;
        }

        if (count($rawFiles) > 10) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'You can upload a maximum of 10 files per order.']);
            exit;
        }

        // Validate each uploaded file
        $storedFiles = [];
        $finfo = finfo_open(FILEINFO_MIME_TYPE);

        foreach ($rawFiles as $idx => $rf) {
            if ($rf['error'] !== UPLOAD_ERR_OK) {
                finfo_close($finfo);
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Upload error on file "' . basename($rf['name']) . '".']);
                exit;
            }

            if ($rf['size'] <= 0 || $rf['size'] > MAX_FILE_SIZE_BYTES) {
                finfo_close($finfo);
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'File "' . basename($rf['name']) . '" exceeds maximum limit of 25 MB.']);
                exit;
            }

            $origName = basename($rf['name']);
            $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
            if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
                finfo_close($finfo);
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'File "' . $origName . '" has unsupported format. Use PDF, JPG, or PNG.']);
                exit;
            }

            $mime = finfo_file($finfo, $rf['tmp_name']);
            if (!in_array($mime, ALLOWED_MIME_TYPES, true)) {
                finfo_close($finfo);
                http_response_code(400);
                echo json_encode(['success' => false, 'error' => 'Invalid file format for "' . $origName . '".']);
                exit;
            }

            $uniqueStored = bin2hex(random_bytes(14)) . '.' . $ext;
            $dest = UPLOAD_DIR . $uniqueStored;

            if (!move_uploaded_file($rf['tmp_name'], $dest)) {
                finfo_close($finfo);
                http_response_code(500);
                echo json_encode(['success' => false, 'error' => 'Failed to store uploaded file.']);
                exit;
            }

            // Determine pages for this individual file
            $isPdf = ($ext === 'pdf' || str_contains($mime, 'pdf'));
            $filePageCount = 1;

            if ($isPdf) {
                $det = detect_pdf_page_count($dest);
                if ($det === false || $det <= 0) {
                    @unlink($dest);
                    finfo_close($finfo);
                    http_response_code(422);
                    echo json_encode(['success' => false, 'error' => 'Unable to read page count of PDF "' . $origName . '". Ensure it is not password protected.']);
                    exit;
                }
                $filePageCount = $det;
            }

            $storedFiles[] = [
                'original_name' => $origName,
                'stored_name'   => $uniqueStored,
                'file_path'     => $dest,
                'file_type'     => $mime,
                'file_size'     => filesize($dest),
                'page_count'    => $filePageCount,
                'is_pdf'        => $isPdf,
                'sort_order'    => $idx + 1
            ];
        }
        finfo_close($finfo);

        // Check if custom PDF pages were requested
        // Supports selected_pages param (e.g. "2,5,8" or JSON array)
        $rawSelectedPages = trim($_POST['selected_pages'] ?? '');
        
        // Single file scenario
        if (count($storedFiles) === 1) {
            $sf = $storedFiles[0];
            $primaryFileName   = $sf['original_name'];
            $primaryStoredName = $sf['stored_name'];
            $primaryFilePath   = $sf['file_path'];
            $primaryMimeType   = $sf['file_type'];

            if ($sf['is_pdf'] && !empty($rawSelectedPages) && $rawSelectedPages !== 'all') {
                $validPages = validate_selected_pdf_pages($sf['page_count'], $rawSelectedPages);
                $totalPageCount = count($validPages);
                $selectedPagesSummary = implode(',', $validPages);
                $sf['selected_pages'] = $selectedPagesSummary;
            } else {
                $totalPageCount = $sf['page_count'];
                $selectedPagesSummary = $sf['is_pdf'] ? '1-' . $sf['page_count'] : '1';
                $sf['selected_pages'] = $selectedPagesSummary;
            }

            $orderFiles[] = $sf;

        // Multiple files scenario
        } else {
            // Check if all files are images -> Compile them into a single A4 PDF for print agent
            $allImages = true;
            $imagePaths = [];
            $sumPages = 0;

            foreach ($storedFiles as $sf) {
                if ($sf['is_pdf']) {
                    $allImages = false;
                } else {
                    $imagePaths[] = $sf['file_path'];
                }
                $sumPages += $sf['page_count'];
                $orderFiles[] = $sf;
            }

            $totalPageCount = $sumPages;

            if ($allImages && count($imagePaths) > 1) {
                // Compile images to a single print-ready A4 PDF document
                $primaryStoredName = 'order_' . bin2hex(random_bytes(12)) . '.pdf';
                $primaryFilePath   = UPLOAD_DIR . $primaryStoredName;
                $primaryFileName   = 'PrintOrder_' . count($imagePaths) . '_Images.pdf';
                $primaryMimeType   = 'application/pdf';

                if (!compile_images_to_a4_pdf($imagePaths, $primaryFilePath)) {
                    // Fallback to first image if compilation failed
                    $primaryStoredName = $storedFiles[0]['stored_name'];
                    $primaryFilePath   = $storedFiles[0]['file_path'];
                    $primaryFileName   = $storedFiles[0]['original_name'];
                    $primaryMimeType   = $storedFiles[0]['file_type'];
                }
            } else {
                // First file represents primary print job record
                $primaryStoredName = $storedFiles[0]['stored_name'];
                $primaryFilePath   = $storedFiles[0]['file_path'];
                $primaryFileName   = 'PrintOrder_' . count($storedFiles) . '_files.pdf';
                $primaryMimeType   = $storedFiles[0]['file_type'];
            }
        }
    }

    // 5. Calculate Verified Order Price Server-Side
    $priceResult = calculate_order_price($db, $shop['id'], $paperSize, $colorMode, $sideMode, $totalPageCount, $copies);
    if (!$priceResult['success']) {
        // Cleanup created primary file if newly created
        if (file_exists($primaryFilePath) && str_starts_with(basename($primaryFilePath), 'idcard_')) {
            @unlink($primaryFilePath);
        }
        http_response_code(422);
        echo json_encode(['success' => false, 'error' => $priceResult['error']]);
        exit;
    }

    $totalAmount = $priceResult['total_amount'];
    $amountPaise = (int)round($totalAmount * 100);

    // 6. Auto-assign Primary Shop Printer
    $stmt = $db->prepare("
        SELECT id FROM printers 
        WHERE shop_id = :shop_id 
        ORDER BY (status IN ('online', 'idle')) DESC, id ASC 
        LIMIT 1
    ");
    $stmt->execute([':shop_id' => $shop['id']]);
    $autoPrinter = $stmt->fetch();
    $printerId = $autoPrinter ? (int)$autoPrinter['id'] : null;

    // 7. Initial Status based on Payment Method
    if ($paymentMethod === 'CASH') {
        $initialJobStatus     = 'AWAITING_SHOP_APPROVAL';
        $initialPaymentStatus = 'pending_cash';
    } else {
        $initialJobStatus     = 'PAYMENT_PENDING';
        $initialPaymentStatus = 'pending';
    }

    // 8. Insert Master Print Job Record
    $stmt = $db->prepare("
        INSERT INTO print_jobs (
            public_token, shop_id, printer_id, file_name, stored_file_name, file_path, file_type, 
            page_count, copies, paper_size, color_mode, side_mode, 
            amount, status, payment_status, payment_method, is_id_card, selected_pages
        ) VALUES (
            :public_token, :shop_id, :printer_id, :file_name, :stored_file_name, :file_path, :file_type, 
            :page_count, :copies, :paper_size, :color_mode, :side_mode, 
            :amount, :status, :payment_status, :payment_method, :is_id_card, :selected_pages
        )
    ");
    $stmt->execute([
        ':public_token'     => $publicToken,
        ':shop_id'          => $shop['id'],
        ':printer_id'       => $printerId,
        ':file_name'        => $primaryFileName,
        ':stored_file_name' => $primaryStoredName,
        ':file_path'        => $primaryFilePath,
        ':file_type'        => $primaryMimeType,
        ':page_count'       => $totalPageCount,
        ':copies'           => $copies,
        ':paper_size'       => $paperSize,
        ':color_mode'       => $colorMode,
        ':side_mode'        => $sideMode,
        ':amount'           => $totalAmount,
        ':status'           => $initialJobStatus,
        ':payment_status'   => $initialPaymentStatus,
        ':payment_method'   => $paymentMethod,
        ':is_id_card'       => $isIdCard,
        ':selected_pages'   => $selectedPagesSummary
    ]);
    $jobId = (int)$db->lastInsertId();

    // 9. Insert Child File Records into print_job_files
    if (!empty($orderFiles)) {
        $stmtChild = $db->prepare("
            INSERT INTO print_job_files (
                job_id, original_name, stored_name, file_path, file_type, file_size, page_count, selected_pages, sort_order
            ) VALUES (
                :job_id, :orig_name, :stored_name, :file_path, :file_type, :file_size, :page_count, :selected_pages, :sort_order
            )
        ");
        foreach ($orderFiles as $of) {
            $stmtChild->execute([
                ':job_id'         => $jobId,
                ':orig_name'      => $of['original_name'],
                ':stored_name'    => $of['stored_name'],
                ':file_path'      => $of['file_path'],
                ':file_type'      => $of['file_type'],
                ':file_size'      => $of['file_size'] ?? 0,
                ':page_count'     => $of['page_count'] ?? 1,
                ':selected_pages' => $of['selected_pages'] ?? null,
                ':sort_order'     => $of['sort_order'] ?? 1
            ]);
        }
    }

    // ==========================================
    // 10A. CASH PAYMENT WORKFLOW
    // ==========================================
    if ($paymentMethod === 'CASH') {
        echo json_encode([
            'success'          => true,
            'token'            => $publicToken,
            'job_id'           => $jobId,
            'payment_method'   => 'CASH',
            'status'           => 'AWAITING_SHOP_APPROVAL',
            'payment_status'   => 'pending_cash',
            'total_rupees'     => $totalAmount,
            'formatted_amount' => format_currency($totalAmount),
            'currency'         => 'INR',
            'shop_name'        => $shop['name'],
            'page_count'       => $totalPageCount,
            'copies'           => $copies,
            'redirect_url'     => APP_URL . '/customer/order-success.php?token=' . urlencode($publicToken)
        ]);
        exit;
    }

    // ==========================================
    // 10B. ONLINE RAZORPAY PAYMENT WORKFLOW
    // ==========================================
    $receiptId = 'rcpt_job_' . $jobId . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    $notes = [
        'job_id'       => (string)$jobId,
        'public_token' => $publicToken,
        'shop_id'      => (string)$shop['id'],
        'shop_name'    => $shop['name'],
        'pages'        => (string)$totalPageCount,
        'copies'       => (string)$copies
    ];

    $customShopKeyId = !empty($shop['razorpay_key_id']) ? trim($shop['razorpay_key_id']) : null;
    $customShopKeySecret = !empty($shop['razorpay_key_secret']) ? trim($shop['razorpay_key_secret']) : null;

    $rzpOrder = razorpay_create_order($amountPaise, $receiptId, $notes, $customShopKeyId, $customShopKeySecret);

    if (!$rzpOrder || empty($rzpOrder['order_id'])) {
        http_response_code(502);
        echo json_encode([
            'success' => false,
            'error'   => 'Payment gateway initialization failed. Please contact the counter staff.'
        ]);
        exit;
    }

    $activeKeyId = !empty($customShopKeyId) ? $customShopKeyId : RAZORPAY_KEY_ID;

    // Record in payments table
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

    // Return Instant Online Checkout Payload
    echo json_encode([
        'success'          => true,
        'token'            => $publicToken,
        'job_id'           => $jobId,
        'payment_method'   => 'ONLINE',
        'order_id'         => $rzpOrder['order_id'],
        'key_id'           => $activeKeyId,
        'amount'           => $amountPaise,
        'total_rupees'     => $totalAmount,
        'formatted_amount' => format_currency($totalAmount),
        'currency'         => 'INR',
        'shop_name'        => $shop['name'],
        'page_count'       => $totalPageCount,
        'copies'           => $copies,
        'redirect_url'     => APP_URL . '/customer/order-success.php?token=' . urlencode($publicToken)
    ]);

} catch (Throwable $e) {
    error_log("Quick checkout error: " . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false, 
        'error'   => 'An unexpected error occurred while preparing your print order.'
    ]);
}
