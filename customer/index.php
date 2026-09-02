<?php
/**
 * PrimePrint Customer Portal V2 — Ultra-Simple Mobile-First Printing Experience
 * Supports: Multi-file uploads, PDF page picker, ID Card A4 composition, Online & Cash payments.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/helpers.php';

if (!isset($shop) || empty($shop)) {
    header("Location: " . APP_URL . "/login.php");
    exit;
}

$db = getDBConnection();

// Fetch active pricing combinations for this shop (strictly A4 and single-sided)
$stmt = $db->prepare("
    SELECT color_mode, price_per_page 
    FROM pricing 
    WHERE shop_id = :shop_id 
      AND paper_size = 'A4' 
      AND side_mode = 'single' 
      AND active = 1
");
$stmt->execute([':shop_id' => $shop['id']]);
$pricingRows = $stmt->fetchAll();

$rates = [
    'BW'    => 2.00,
    'COLOR' => 10.00
];
foreach ($pricingRows as $r) {
    $mode = strtoupper($r['color_mode']);
    if (isset($rates[$mode])) {
        $rates[$mode] = (float)$r['price_per_page'];
    }
}

// Standard Form POST Handler (Full Fallback & Backward-Compatibility Support)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $paperSize = 'A4';
    $requestedPaperSize = strtoupper(trim($_POST['paper_size'] ?? 'A4'));
    if ($requestedPaperSize === 'A3') {
        http_response_code(400);
        die("A3 printing is not supported. Please choose A4.");
    }

    $colorMode = (strtoupper(trim($_POST['color_mode'] ?? 'BW')) === 'COLOR') ? 'COLOR' : 'BW';
    $sideMode  = 'single';
    $copies    = (int)($_POST['copies'] ?? 1);
    $copies    = max(1, min(100, $copies));

    $rawFile = null;
    if (isset($_FILES['document']) && $_FILES['document']['error'] === UPLOAD_ERR_OK) {
        $rawFile = $_FILES['document'];
    } elseif (isset($_FILES['documents']) && is_array($_FILES['documents']['name']) && !empty($_FILES['documents']['name'][0])) {
        $rawFile = [
            'name'     => $_FILES['documents']['name'][0],
            'type'     => $_FILES['documents']['type'][0],
            'tmp_name' => $_FILES['documents']['tmp_name'][0],
            'error'    => $_FILES['documents']['error'][0],
            'size'     => $_FILES['documents']['size'][0]
        ];
    }

    if (!$rawFile || $rawFile['error'] !== UPLOAD_ERR_OK) {
        die("Please choose a valid document to upload.");
    }

    $origName = basename($rawFile['name']);
    $fileSize = (int)$rawFile['size'];
    $tmpPath  = $rawFile['tmp_name'];

    if ($fileSize <= 0 || $fileSize > MAX_FILE_SIZE_BYTES) {
        die("File size exceeds 25 MB.");
    }

    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
    if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
        die("Unsupported format. Use PDF, JPG, or PNG.");
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    if (!in_array($mimeType, ALLOWED_MIME_TYPES, true)) {
        die("Invalid file format ({$mimeType}).");
    }

    if (!is_dir(UPLOAD_DIR)) {
        mkdir(UPLOAD_DIR, 0755, true);
    }

    $storedFileName = bin2hex(random_bytes(14)) . '.' . $ext;
    $destination = UPLOAD_DIR . $storedFileName;

    if (!move_uploaded_file($tmpPath, $destination)) {
        die("Failed to store file.");
    }

    $isPdf = ($ext === 'pdf' || str_contains($mimeType, 'pdf'));
    $pageCount = 1;
    if ($isPdf) {
        $det = detect_pdf_page_count($destination);
        if ($det === false || $det <= 0) {
            @unlink($destination);
            die("Unable to read PDF page count.");
        }
        $pageCount = $det;
    }

    $priceResult = calculate_order_price($db, $shop['id'], $paperSize, $colorMode, $sideMode, $pageCount, $copies);
    if (!$priceResult['success']) {
        @unlink($destination);
        die($priceResult['error']);
    }

    $totalAmount = $priceResult['total_amount'];
    $publicToken = generate_public_order_token($db);

    $stmtPrn = $db->prepare("SELECT id FROM printers WHERE shop_id = :shop_id ORDER BY (status IN ('online', 'idle')) DESC, id ASC LIMIT 1");
    $stmtPrn->execute([':shop_id' => $shop['id']]);
    $prn = $stmtPrn->fetch();
    $printerId = $prn ? (int)$prn['id'] : null;

    $stmtIns = $db->prepare("
        INSERT INTO print_jobs (
            public_token, shop_id, printer_id, file_name, stored_file_name, file_path, file_type, 
            page_count, copies, paper_size, color_mode, side_mode, 
            amount, status, payment_status, payment_method
        ) VALUES (
            :token, :shop_id, :printer_id, :file_name, :stored_file_name, :file_path, :file_type, 
            :page_count, :copies, :paper_size, :color_mode, :side_mode, 
            :amount, 'PAYMENT_PENDING', 'pending', 'ONLINE'
        )
    ");
    $stmtIns->execute([
        ':token'            => $publicToken,
        ':shop_id'          => $shop['id'],
        ':printer_id'       => $printerId,
        ':file_name'        => $origName,
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

    header("Location: " . APP_URL . "/customer/review.php?token=" . urlencode($publicToken));
    exit;
}

$csrfToken = get_csrf_token();
$pageTitle = 'Print at ' . e($shop['name']) . ' — ' . APP_NAME;
?>

<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
  <title><?= e($pageTitle) ?></title>
  
  <!-- Bootstrap 5.3 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <!-- Cropper.js for ID Card Crop -->
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.css">
  <!-- Custom Modern Design System -->
  <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">

  <style>
    :root {
      --pp-primary: #2563eb;
      --pp-primary-dark: #1d4ed8;
      --pp-bg: #f8fafc;
    }
    body.customer-portal-v2 {
      background-color: var(--pp-bg);
      font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
      color: #1e293b;
      min-height: 100vh;
      padding-bottom: 120px !important;
      overflow-x: hidden;
    }
    .portal-container {
      width: 100%;
      max-width: 520px;
      margin: 0 auto;
      padding: 12px 14px 24px;
    }
    .shop-header-card {
      background: #ffffff;
      border-radius: 16px;
      padding: 14px 16px;
      border: 1px solid #e2e8f0;
      box-shadow: 0 2px 8px rgba(0,0,0,0.03);
      margin-bottom: 14px;
    }
    .step-pill-bar {
      display: flex;
      align-items: center;
      justify-content: space-between;
      margin-bottom: 14px;
      padding: 6px 10px;
      background: #ffffff;
      border-radius: 30px;
      border: 1px solid #e2e8f0;
      font-size: 0.74rem;
      font-weight: 600;
    }
    .step-pill-item {
      display: flex;
      align-items: center;
      gap: 4px;
      color: #94a3b8;
      white-space: nowrap;
    }
    .step-pill-item.active {
      color: var(--pp-primary);
    }
    .step-pill-item.active .step-num {
      background: var(--pp-primary);
      color: #fff;
    }
    .step-num {
      width: 18px;
      height: 18px;
      border-radius: 50%;
      background: #e2e8f0;
      color: #64748b;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 0.7rem;
    }
    /* Mode Selector Cards */
    .mode-card {
      border: 2px solid #e2e8f0;
      background: #ffffff;
      border-radius: 14px;
      padding: 12px 10px;
      cursor: pointer;
      transition: all 0.2s ease;
      text-align: left;
      height: 100%;
      display: flex;
      flex-direction: column;
      justify-content: space-between;
      min-height: 110px;
    }
    .mode-card:hover {
      border-color: #cbd5e1;
    }
    .mode-card.active {
      border-color: var(--pp-primary);
      background: #eff6ff;
      box-shadow: 0 0 0 3px rgba(37,99,235,0.12);
    }
    .mode-icon {
      width: 36px;
      height: 36px;
      border-radius: 10px;
      background: #f1f5f9;
      color: #334155;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.15rem;
      margin-bottom: 6px;
    }
    .mode-card.active .mode-icon {
      background: var(--pp-primary);
      color: #ffffff;
    }
    .mode-card .mode-title {
      font-weight: 700;
      font-size: 0.88rem;
      color: #0f172a;
      line-height: 1.25;
      margin-bottom: 2px;
    }
    .mode-card .mode-desc {
      font-size: 0.7rem;
      color: #64748b;
      line-height: 1.2;
    }
    /* Upload Dropzone */
    .upload-zone {
      border: 2px dashed #cbd5e1;
      background: #ffffff;
      border-radius: 14px;
      padding: 22px 14px;
      text-align: center;
      cursor: pointer;
      transition: all 0.2s ease;
    }
    .upload-zone:hover, .upload-zone.dragover {
      border-color: var(--pp-primary);
      background: #f0f7ff;
    }
    /* File Card Item */
    .file-item-card {
      background: #ffffff;
      border: 1px solid #e2e8f0;
      border-radius: 12px;
      padding: 10px 12px;
      margin-bottom: 8px;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 8px;
    }
    .file-thumb {
      width: 36px;
      height: 36px;
      border-radius: 8px;
      background: #f1f5f9;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 1.15rem;
      flex-shrink: 0;
      overflow: hidden;
    }
    .file-thumb img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    /* Choice Buttons (Color Mode & Payment) */
    .choice-btn-group {
      display: grid;
      grid-template-columns: 1fr 1fr;
      gap: 8px;
    }
    .choice-btn {
      border: 2px solid #e2e8f0;
      background: #ffffff;
      border-radius: 12px;
      padding: 12px 10px;
      text-align: center;
      cursor: pointer;
      font-weight: 600;
      transition: all 0.15s ease;
      user-select: none;
    }
    .choice-btn:hover {
      border-color: #cbd5e1;
    }
    .choice-btn.active {
      border-color: var(--pp-primary);
      background: #eff6ff;
      color: var(--pp-primary);
    }
    /* Stepper (Copies) */
    .stepper-box {
      display: inline-flex;
      align-items: center;
      border: 1px solid #cbd5e1;
      border-radius: 10px;
      background: #ffffff;
      overflow: hidden;
    }
    .stepper-btn {
      width: 40px;
      height: 38px;
      background: #f8fafc;
      border: none;
      font-size: 1.15rem;
      font-weight: bold;
      color: #334155;
      cursor: pointer;
      display: flex;
      align-items: center;
      justify-content: center;
      transition: background 0.15s;
    }
    .stepper-btn:hover {
      background: #e2e8f0;
    }
    .stepper-val {
      width: 44px;
      text-align: center;
      font-weight: 700;
      font-size: 1rem;
      border: none;
      background: transparent;
      outline: none;
    }
    /* ID Card Upload Cards */
    .id-side-box {
      border: 2px dashed #cbd5e1;
      border-radius: 12px;
      background: #ffffff;
      padding: 14px 10px;
      text-align: center;
      cursor: pointer;
      transition: all 0.2s;
    }
    .id-side-box.has-image {
      border-style: solid;
      border-color: #10b981;
      background: #f0fdf4;
    }
    .id-side-preview {
      max-height: 100px;
      max-width: 100%;
      border-radius: 6px;
      object-fit: contain;
      box-shadow: 0 2px 6px rgba(0,0,0,0.1);
    }
    /* A4 Live Composite Preview */
    .a4-preview-canvas {
      width: 100%;
      aspect-ratio: 1 / 1.414;
      background: #ffffff;
      border: 1px solid #cbd5e1;
      border-radius: 8px;
      box-shadow: 0 4px 16px rgba(0,0,0,0.08);
      position: relative;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      align-items: center;
      justify-content: space-around;
      padding: 14px 10px;
      margin: 0 auto;
      max-width: 240px;
    }
    .a4-card-slot {
      width: 78%;
      height: 40%;
      border-radius: 6px;
      object-fit: contain;
      box-shadow: 0 2px 6px rgba(0,0,0,0.12);
    }
    /* Sticky Bottom Checkout Bar */
    .checkout-sticky-footer {
      position: fixed;
      bottom: 0;
      left: 0;
      right: 0;
      background: #ffffff;
      border-top: 1px solid #e2e8f0;
      padding: 10px 14px;
      padding-bottom: max(10px, env(safe-area-inset-bottom, 10px));
      z-index: 1050;
      box-shadow: 0 -4px 16px rgba(0,0,0,0.08);
    }
    .checkout-sticky-inner {
      max-width: 520px;
      margin: 0 auto;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 10px;
    }
    /* PDF Page Selector Card */
    .pdf-page-tile {
      border: 2px solid #e2e8f0;
      border-radius: 8px;
      padding: 4px;
      text-align: center;
      cursor: pointer;
      position: relative;
      background: #ffffff;
      transition: all 0.15s ease;
    }
    .pdf-page-tile.selected {
      border-color: var(--pp-primary);
      background: #eff6ff;
    }
    .check-badge {
      position: absolute;
      top: 4px;
      right: 4px;
      width: 18px;
      height: 18px;
      border-radius: 50%;
      background: var(--pp-primary);
      color: #fff;
      display: none;
      align-items: center;
      justify-content: center;
      font-size: 0.68rem;
    }
    .pdf-page-tile.selected .check-badge {
      display: flex;
    }
    .pdf-page-canvas {
      width: 100%;
      height: auto;
      border-radius: 4px;
      border: 1px solid #f1f5f9;
      background: #fafafa;
    }
  </style>
</head>
<body class="customer-portal-v2">

  <div class="portal-container">

    <!-- Top Shop Branding Header -->
    <div class="shop-header-card text-center">
      <div class="d-inline-flex align-items-center justify-content-center bg-primary-subtle text-primary rounded-circle mb-2" style="width: 44px; height: 44px; font-size: 1.3rem;">
        <i class="bi bi-shop"></i>
      </div>
      <h2 class="fw-bold text-dark mb-1 fs-4"><?= e($shop['name']) ?></h2>
      <p class="text-muted small mb-0">Upload your file, choose how you want it printed, and you're done.</p>
    </div>

    <!-- Step Progress Indicator -->
    <div class="step-pill-bar">
      <div class="step-pill-item active" id="stepPill1">
        <span class="step-num">1</span> Upload
      </div>
      <i class="bi bi-chevron-right text-muted opacity-50 small"></i>
      <div class="step-pill-item" id="stepPill2">
        <span class="step-num">2</span> Choose
      </div>
      <i class="bi bi-chevron-right text-muted opacity-50 small"></i>
      <div class="step-pill-item" id="stepPill3">
        <span class="step-num">3</span> Pay
      </div>
    </div>

    <!-- Form -->
    <form id="printOrderForm" enctype="multipart/form-data" method="POST" action="<?= APP_URL ?>/api/customer/quick-checkout.php">
      <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
      <input type="hidden" name="shop_slug" value="<?= e($shop['slug']) ?>">
      <input type="hidden" name="print_mode" id="printModeInput" value="regular">
      <input type="hidden" name="paper_size" value="A4">
      <input type="hidden" name="side_mode" value="single">
      <input type="hidden" name="color_mode" id="colorModeInput" value="BW">
      <input type="hidden" name="copies" id="copiesInput" value="1">
      <input type="hidden" name="payment_method" id="paymentMethodInput" value="ONLINE">
      <input type="hidden" name="selected_pages" id="selectedPagesInput" value="">
      
      <!-- Hidden base64 containers for cropped ID cards -->
      <input type="hidden" name="id_front_data" id="idFrontData">
      <input type="hidden" name="id_back_data" id="idBackData">

      <!-- ============================================== -->
      <!-- SECTION 1: WHAT DO YOU WANT TO PRINT? (MODE)    -->
      <!-- ============================================== -->
      <div class="mb-4">
        <label class="form-label small fw-bold text-secondary text-uppercase mb-2" style="letter-spacing: 0.05em;">
          What do you want to print?
        </label>
        <div class="row g-2">
          
          <!-- Mode 1: Documents & Photos -->
          <div class="col-6">
            <div class="mode-card active" id="modeCardRegular" onclick="selectPrintMode('regular')">
              <div class="mode-icon">
                <i class="bi bi-file-earmark-text"></i>
              </div>
              <div class="mode-title">Documents & Photos</div>
              <div class="mode-desc">PDF, JPG, or PNG files</div>
            </div>
          </div>

          <!-- Mode 2: ID Card / Aadhaar -->
          <div class="col-6">
            <div class="mode-card" id="modeCardId" onclick="selectPrintMode('id_card')">
              <div class="mode-icon">
                <i class="bi bi-person-vcard"></i>
              </div>
              <div class="mode-title">ID Card / Aadhaar</div>
              <div class="mode-desc">Front & back on 1 A4 page</div>
            </div>
          </div>

        </div>
      </div>

      <!-- ============================================== -->
      <!-- SECTION 2A: REGULAR PRINT (DOCUMENTS & PHOTOS) -->
      <!-- ============================================== -->
      <div id="regularPrintSection">
        
        <!-- Multi-file Input (Hidden) -->
        <input type="file" id="multiFileInput" name="documents[]" multiple accept=".pdf,.jpg,.jpeg,.png" style="display:none;">

        <!-- Dropzone / Upload Action -->
        <div class="upload-zone mb-3" id="dropzoneBox" onclick="document.getElementById('multiFileInput').click()">
          <div class="d-inline-flex align-items-center justify-content-center bg-light text-primary rounded-circle mb-2" style="width: 52px; height: 52px; font-size: 1.5rem;">
            <i class="bi bi-cloud-arrow-up-fill"></i>
          </div>
          <div class="fw-bold text-dark fs-6">Choose Files</div>
          <div class="text-muted small mt-1">Tap to select PDFs or photos (up to 10 files)</div>
        </div>

        <!-- Selected Files List -->
        <div id="fileListContainer" class="mb-3" style="display:none;">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="small fw-bold text-secondary text-uppercase" style="letter-spacing: 0.05em;">Your Files</span>
            <button type="button" class="btn btn-sm btn-outline-primary py-1 px-2 rounded-pill small" onclick="document.getElementById('multiFileInput').click()">
              <i class="bi bi-plus-lg me-1"></i>Add more files
            </button>
          </div>
          <div id="fileItemsList"></div>
        </div>

      </div>

      <!-- ============================================== -->
      <!-- SECTION 2B: ID CARD / AADHAAR WORKFLOW        -->
      <!-- ============================================== -->
      <div id="idCardPrintSection" style="display:none;">
        
        <input type="file" id="idFrontInput" accept="image/*" style="display:none;">
        <input type="file" id="idBackInput" accept="image/*" style="display:none;">

        <div class="row g-2 mb-3">
          
          <!-- Front Side Slot -->
          <div class="col-6">
            <div class="id-side-box" id="idFrontBox" onclick="document.getElementById('idFrontInput').click()">
              <div id="idFrontPlaceholder">
                <i class="bi bi-card-heading text-primary fs-3 d-block mb-1"></i>
                <div class="fw-bold small text-dark">Front Side</div>
                <div class="text-muted" style="font-size: 0.72rem;">Tap to upload & crop</div>
              </div>
              <div id="idFrontPreviewWrapper" style="display:none;">
                <img id="idFrontPreviewImg" class="id-side-preview mb-1" alt="Front Preview">
                <div class="text-success small fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>Front Cropped</div>
              </div>
            </div>
          </div>

          <!-- Back Side Slot -->
          <div class="col-6">
            <div class="id-side-box" id="idBackBox" onclick="document.getElementById('idBackInput').click()">
              <div id="idBackPlaceholder">
                <i class="bi bi-credit-card-2-front text-primary fs-3 d-block mb-1"></i>
                <div class="fw-bold small text-dark">Back Side</div>
                <div class="text-muted" style="font-size: 0.72rem;">Tap to upload & crop</div>
              </div>
              <div id="idBackPreviewWrapper" style="display:none;">
                <img id="idBackPreviewImg" class="id-side-preview mb-1" alt="Back Preview">
                <div class="text-success small fw-semibold"><i class="bi bi-check-circle-fill me-1"></i>Back Cropped</div>
              </div>
            </div>
          </div>

        </div>

        <!-- A4 Composition Live Preview -->
        <div id="idA4PreviewSection" class="card card-body bg-light border-0 p-3 mb-3 text-center" style="display:none;">
          <div class="small fw-bold text-secondary text-uppercase mb-2" style="letter-spacing: 0.05em;">A4 Page Preview (1 Page)</div>
          <div class="a4-preview-canvas">
            <img id="a4SlotFront" class="a4-card-slot" alt="Front">
            <div style="border-top: 1px dashed #cbd5e1; width: 85%;"></div>
            <img id="a4SlotBack" class="a4-card-slot" alt="Back">
          </div>
          <div class="text-muted small mt-2">Both sides arranged cleanly on 1 sheet of A4 paper.</div>
        </div>

      </div>

      <!-- ============================================== -->
      <!-- SECTION 3: HOW DO YOU WANT IT PRINTED? (PREFS) -->
      <!-- ============================================== -->
      <div class="card card-body bg-white border rounded-4 p-3 mb-3">
        
        <div class="d-flex align-items-center justify-content-between mb-3">
          <span class="small fw-bold text-secondary text-uppercase" style="letter-spacing: 0.05em;">Paper Size</span>
          <span class="badge bg-light text-dark border px-3 py-2 fw-bold" style="font-size: 0.85rem;">
            <i class="bi bi-file-earmark me-1 text-primary"></i> A4 Standard
          </span>
        </div>

        <!-- Color Mode Selection -->
        <div class="mb-3">
          <label class="form-label small fw-bold text-secondary text-uppercase mb-2" style="letter-spacing: 0.05em;">
            Color Mode
          </label>
          <div class="choice-btn-group">
            <div class="choice-btn active" id="btnColorBW" onclick="selectColorMode('BW')">
              <i class="bi bi-circle-half me-1"></i> Black & White
              <div class="small text-muted fw-normal" style="font-size: 0.75rem;"><?= format_currency($rates['BW']) ?>/page</div>
            </div>
            <div class="choice-btn" id="btnColorColor" onclick="selectColorMode('COLOR')">
              <i class="bi bi-palette-fill me-1 text-danger"></i> Full Color
              <div class="small text-muted fw-normal" style="font-size: 0.75rem;"><?= format_currency($rates['COLOR']) ?>/page</div>
            </div>
          </div>
        </div>

        <!-- Copies Stepper -->
        <div class="d-flex align-items-center justify-content-between pt-2 border-top">
          <div>
            <div class="fw-bold text-dark fs-6">Number of Copies</div>
            <div class="text-muted small">How many sets do you need?</div>
          </div>
          <div class="stepper-box">
            <button type="button" class="stepper-btn" onclick="stepCopies(-1)">−</button>
            <input type="text" class="stepper-val" id="copiesDisplay" value="1" readonly>
            <button type="button" class="stepper-btn" onclick="stepCopies(1)">+</button>
          </div>
        </div>

      </div>

      <!-- ============================================== -->
      <!-- SECTION 4: PAYMENT METHOD                      -->
      <!-- ============================================== -->
      <div class="card card-body bg-white border rounded-4 p-3 mb-4">
        <label class="form-label small fw-bold text-secondary text-uppercase mb-2" style="letter-spacing: 0.05em;">
          Payment Method
        </label>
        
        <div class="choice-btn-group">
          <!-- Pay Online -->
          <div class="choice-btn active text-start p-3" id="btnPayOnline" onclick="selectPaymentMethod('ONLINE')">
            <div class="d-flex align-items-center justify-content-between mb-1">
              <span class="fw-bold fs-6"><i class="bi bi-qr-code text-primary me-2"></i>Pay Online</span>
              <span class="badge bg-success-subtle text-success small">Instant</span>
            </div>
            <div class="text-muted small" style="font-size: 0.74rem;">UPI, GPay, PhonePe, Cards</div>
          </div>

          <!-- Pay at Counter (Cash) -->
          <div class="choice-btn text-start p-3" id="btnPayCash" onclick="selectPaymentMethod('CASH')">
            <div class="d-flex align-items-center justify-content-between mb-1">
              <span class="fw-bold fs-6"><i class="bi bi-cash-stack text-warning me-2"></i>Pay at Counter</span>
              <span class="badge bg-warning-subtle text-warning small">Cash</span>
            </div>
            <div class="text-muted small" style="font-size: 0.74rem;">Pay cash to shopkeeper</div>
          </div>
        </div>

      </div>

      <!-- Spacing for fixed checkout bar -->
      <div style="height: 110px;"></div>

      <!-- ============================================== -->
      <!-- STICKY BOTTOM CHECKOUT ACTION BAR              -->
      <!-- ============================================== -->
      <div class="checkout-sticky-footer">
        <div class="checkout-sticky-inner">
          <div class="text-truncate me-2" style="min-width: 90px;">
            <div class="small text-muted text-truncate" id="pricePagesSummary">0 pgs • 1 copy</div>
            <div class="fs-4 fw-bold text-dark font-heading" id="totalPriceDisplay">₹0.00</div>
          </div>
          <button type="submit" id="btnMainSubmit" class="btn btn-primary fw-bold px-3 py-2 rounded-3 shadow flex-shrink-0" style="font-size: 0.95rem; min-height: 44px;" disabled>
            <span id="submitBtnSpinner" class="spinner-border spinner-border-sm me-2" style="display:none;"></span>
            <span id="submitBtnText">Select files to print</span>
          </button>
        </div>
      </div>

    </form>

  </div>

  <!-- ============================================== -->
  <!-- MODAL 1: CROPPER MODAL (ID CARD)               -->
  <!-- ============================================== -->
  <div class="modal fade" id="cropModal" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content rounded-4 border-0">
        <div class="modal-header border-0 pb-0">
          <div>
            <h5 class="modal-title fw-bold" id="cropModalTitle">Crop ID Card</h5>
            <div class="text-muted small">Remove the unwanted background around your ID.</div>
          </div>
        </div>
        <div class="modal-body">
          <div style="max-height: 380px; overflow:hidden; border-radius: 8px; background: #000;">
            <img id="cropperImage" src="" style="max-width: 100%; display:block;" alt="Crop image">
          </div>
        </div>
        <div class="modal-footer border-0 pt-0 d-flex justify-content-between">
          <button type="button" class="btn btn-light rounded-pill px-3" onclick="resetCrop()">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Reset
          </button>
          <button type="button" class="btn btn-primary rounded-pill px-4 fw-bold" onclick="confirmCrop()">
            <i class="bi bi-check-lg me-1"></i>Use This Crop
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- ============================================== -->
  <!-- MODAL 2: PDF VISUAL PAGE PICKER MODAL          -->
  <!-- ============================================== -->
  <div class="modal fade" id="pdfPagePickerModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
      <div class="modal-content rounded-4 border-0">
        <div class="modal-header border-bottom">
          <div>
            <h5 class="modal-title fw-bold mb-0">Select Pages to Print</h5>
            <div class="text-muted small" id="pdfPickerSummaryText">All pages selected</div>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="d-flex justify-content-between align-items-center mb-3">
            <button type="button" class="btn btn-sm btn-outline-primary rounded-pill px-3" onclick="selectAllPdfPages()">Select All</button>
            <button type="button" class="btn btn-sm btn-outline-secondary rounded-pill px-3" onclick="clearAllPdfPages()">Clear All</button>
          </div>
          <div class="row g-2" id="pdfPagesGrid"></div>
        </div>
        <div class="modal-footer border-top">
          <button type="button" class="btn btn-primary w-100 fw-bold py-2 rounded-3" data-bs-dismiss="modal">
            Confirm Selection
          </button>
        </div>
      </div>
    </div>
  </div>

  <!-- CDNs & Scripts -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/cropperjs/1.6.2/cropper.min.js"></script>
  <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
  <script src="<?= asset_url('assets/js/pdf-lib.min.js') ?>"></script>
  <script src="https://checkout.razorpay.com/v1/checkout.js"></script>

  <script>
    // Set PDF.js worker
    if (window.pdfjsLib) {
      pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    }

    // Config & Pricing Rates
    const rates = <?= json_encode($rates) ?>;
    const shopSlug = <?= json_encode($shop['slug']) ?>;
    const shopName = <?= json_encode($shop['name']) ?>;
    const appUrl = <?= json_encode(APP_URL) ?>;

    // State
    let currentMode = 'regular'; // 'regular' or 'id_card'
    let currentColorMode = 'BW';
    let currentCopies = 1;
    let currentPaymentMethod = 'ONLINE';
    
    // Regular Files State
    let selectedFiles = []; // Array of { file, name, size, type, isPdf, totalPages, selectedPages: [1, 2, ...] }
    
    // ID Card State
    let idFrontBlob = null;
    let idBackBlob = null;
    let activeCropSide = 'front';
    let cropperInstance = null;
    const cropModal = new bootstrap.Modal(document.getElementById('cropModal'));
    const pdfPagePickerModal = new bootstrap.Modal(document.getElementById('pdfPagePickerModal'));
    let activePdfFileIndex = null;

    // ----------------------------------------------------
    // Mode Switching
    // ----------------------------------------------------
    function selectPrintMode(mode) {
      currentMode = mode;
      document.getElementById('printModeInput').value = mode;

      document.getElementById('modeCardRegular').classList.toggle('active', mode === 'regular');
      document.getElementById('modeCardId').classList.toggle('active', mode === 'id_card');

      document.getElementById('regularPrintSection').style.display = (mode === 'regular') ? 'block' : 'none';
      document.getElementById('idCardPrintSection').style.display = (mode === 'id_card') ? 'block' : 'none';

      updateTotalPricing();
    }

    // ----------------------------------------------------
    // Color Mode & Copies
    // ----------------------------------------------------
    function selectColorMode(mode) {
      currentColorMode = mode;
      document.getElementById('colorModeInput').value = mode;
      document.getElementById('btnColorBW').classList.toggle('active', mode === 'BW');
      document.getElementById('btnColorColor').classList.toggle('active', mode === 'COLOR');
      updateTotalPricing();
    }

    function stepCopies(delta) {
      let val = currentCopies + delta;
      if (val < 1) val = 1;
      if (val > 100) val = 100;
      currentCopies = val;
      document.getElementById('copiesDisplay').value = val;
      document.getElementById('copiesInput').value = val;
      updateTotalPricing();
    }

    function selectPaymentMethod(method) {
      currentPaymentMethod = method;
      document.getElementById('paymentMethodInput').value = method;
      document.getElementById('btnPayOnline').classList.toggle('active', method === 'ONLINE');
      document.getElementById('btnPayCash').classList.toggle('active', method === 'CASH');
      updateTotalPricing();
    }

    // ----------------------------------------------------
    // Regular Print: Multi-File Selection
    // ----------------------------------------------------
    const multiFileInput = document.getElementById('multiFileInput');
    multiFileInput.addEventListener('change', async (e) => {
      const files = Array.from(e.target.files);
      if (!files.length) return;

      if (selectedFiles.length + files.length > 10) {
        alert('You can upload up to 10 files per order.');
        return;
      }

      for (const file of files) {
        // Validate size
        if (file.size > 25 * 1024 * 1024) {
          alert(`File "${file.name}" exceeds 25 MB.`);
          continue;
        }

        const isPdf = file.type.includes('pdf') || file.name.toLowerCase().endsWith('.pdf');
        let totalPages = 1;
        let selectedPages = [1];

        if (isPdf) {
          try {
            const buf = await file.arrayBuffer();
            const pdfDoc = await pdfjsLib.getDocument({ data: buf }).promise;
            totalPages = pdfDoc.numPages || 1;
            selectedPages = Array.from({ length: totalPages }, (_, i) => i + 1);
          } catch (err) {
            console.error('PDF inspect error:', err);
            totalPages = 1;
            selectedPages = [1];
          }
        }

        selectedFiles.push({
          file: file,
          name: file.name,
          size: file.size,
          type: file.type,
          isPdf: isPdf,
          totalPages: totalPages,
          selectedPages: selectedPages
        });
      }

      multiFileInput.value = '';
      renderFileList();
      updateTotalPricing();
    });

    function renderFileList() {
      const container = document.getElementById('fileListContainer');
      const list = document.getElementById('fileItemsList');
      list.innerHTML = '';

      if (selectedFiles.length === 0) {
        container.style.display = 'none';
        return;
      }

      container.style.display = 'block';

      selectedFiles.forEach((f, idx) => {
        const item = document.createElement('div');
        item.className = 'file-item-card';

        let iconOrThumb = f.isPdf ? '<i class="bi bi-file-earmark-pdf-fill text-danger"></i>' : '<i class="bi bi-file-earmark-image-fill text-primary"></i>';
        let pageSummary = f.isPdf 
          ? `<span class="badge bg-light text-dark border px-2 py-1">${f.selectedPages.length} of ${f.totalPages} pgs</span>` 
          : '<span class="badge bg-light text-secondary border px-2 py-1">1 page</span>';

        let choosePagesBtn = (f.isPdf && f.totalPages > 1) 
          ? `<button type="button" class="btn btn-sm btn-outline-primary py-0 px-2 ms-2" style="font-size:0.75rem;" onclick="openPdfPagePicker(${idx})"><i class="bi bi-ui-checks me-1"></i>Pages</button>` 
          : '';

        item.innerHTML = `
          <div class="d-flex align-items-center gap-2 text-truncate" style="max-width: 70%;">
            <div class="file-thumb">${iconOrThumb}</div>
            <div class="text-truncate">
              <div class="fw-semibold text-dark small text-truncate">${escapeHtml(f.name)}</div>
              <div class="text-muted" style="font-size: 0.72rem;">${formatBytes(f.size)}</div>
            </div>
          </div>
          <div class="d-flex align-items-center gap-2">
            ${pageSummary}
            ${choosePagesBtn}
            <button type="button" class="btn btn-sm text-danger p-0" onclick="removeFile(${idx})" title="Remove file">
              <i class="bi bi-x-circle-fill fs-5"></i>
            </button>
          </div>
        `;
        list.appendChild(item);
      });
    }

    function removeFile(idx) {
      selectedFiles.splice(idx, 1);
      renderFileList();
      updateTotalPricing();
    }

    // ----------------------------------------------------
    // PDF Visual Page Picker (PDF.js lazy thumbnail render)
    // ----------------------------------------------------
    async function openPdfPagePicker(fileIndex) {
      activePdfFileIndex = fileIndex;
      const fileObj = selectedFiles[fileIndex];
      if (!fileObj || !fileObj.isPdf) return;

      const grid = document.getElementById('pdfPagesGrid');
      grid.innerHTML = '<div class="text-center py-4"><span class="spinner-border spinner-border-sm text-primary"></span> Loading pages...</div>';
      pdfPagePickerModal.show();

      try {
        const buf = await fileObj.file.arrayBuffer();
        const pdfDoc = await pdfjsLib.getDocument({ data: buf }).promise;
        grid.innerHTML = '';

        for (let pageNum = 1; pageNum <= pdfDoc.numPages; pageNum++) {
          const isSelected = fileObj.selectedPages.includes(pageNum);
          const col = document.createElement('div');
          col.className = 'col-4 col-sm-3';

          const tile = document.createElement('div');
          tile.className = `pdf-page-tile ${isSelected ? 'selected' : ''}`;
          tile.id = `pdfTile_${pageNum}`;
          tile.onclick = () => togglePdfPage(pageNum);

          tile.innerHTML = `
            <div class="check-badge"><i class="bi bi-check"></i></div>
            <canvas id="pdfCanvas_${pageNum}" class="pdf-page-canvas"></canvas>
            <div class="small fw-semibold mt-1" style="font-size:0.75rem;">Page ${pageNum}</div>
          `;
          col.appendChild(tile);
          grid.appendChild(col);

          // Render thumbnail on canvas lazily
          renderPdfThumbnail(pdfDoc, pageNum, `pdfCanvas_${pageNum}`);
        }

        updatePdfPickerSummary();
      } catch (err) {
        grid.innerHTML = '<div class="alert alert-danger small">Failed to load PDF preview.</div>';
      }
    }

    async function renderPdfThumbnail(pdfDoc, pageNum, canvasId) {
      try {
        const page = await pdfDoc.getPage(pageNum);
        const viewport = page.getViewport({ scale: 0.35 });
        const canvas = document.getElementById(canvasId);
        if (!canvas) return;
        const ctx = canvas.getContext('2d');
        canvas.width = viewport.width;
        canvas.height = viewport.height;
        await page.render({ canvasContext: ctx, viewport: viewport }).promise;
      } catch (err) {
        console.error('Thumbnail render error on page ' + pageNum, err);
      }
    }

    function togglePdfPage(pageNum) {
      if (activePdfFileIndex === null) return;
      const fileObj = selectedFiles[activePdfFileIndex];
      const idx = fileObj.selectedPages.indexOf(pageNum);
      if (idx > -1) {
        if (fileObj.selectedPages.length === 1) {
          alert('At least 1 page must remain selected.');
          return;
        }
        fileObj.selectedPages.splice(idx, 1);
      } else {
        fileObj.selectedPages.push(pageNum);
        fileObj.selectedPages.sort((a, b) => a - b);
      }

      const tile = document.getElementById(`pdfTile_${pageNum}`);
      if (tile) tile.classList.toggle('selected', fileObj.selectedPages.includes(pageNum));

      updatePdfPickerSummary();
      renderFileList();
      updateTotalPricing();
    }

    function selectAllPdfPages() {
      if (activePdfFileIndex === null) return;
      const fileObj = selectedFiles[activePdfFileIndex];
      fileObj.selectedPages = Array.from({ length: fileObj.totalPages }, (_, i) => i + 1);
      for (let i = 1; i <= fileObj.totalPages; i++) {
        const tile = document.getElementById(`pdfTile_${i}`);
        if (tile) tile.classList.add('selected');
      }
      updatePdfPickerSummary();
      renderFileList();
      updateTotalPricing();
    }

    function clearAllPdfPages() {
      if (activePdfFileIndex === null) return;
      const fileObj = selectedFiles[activePdfFileIndex];
      // Keep page 1 selected minimum
      fileObj.selectedPages = [1];
      for (let i = 1; i <= fileObj.totalPages; i++) {
        const tile = document.getElementById(`pdfTile_${i}`);
        if (tile) tile.classList.toggle('selected', i === 1);
      }
      updatePdfPickerSummary();
      renderFileList();
      updateTotalPricing();
    }

    function updatePdfPickerSummary() {
      if (activePdfFileIndex === null) return;
      const fileObj = selectedFiles[activePdfFileIndex];
      document.getElementById('pdfPickerSummaryText').textContent = 
        `${fileObj.selectedPages.length} of ${fileObj.totalPages} pages selected`;
    }

    // ----------------------------------------------------
    // ID Card Workflow & Cropper.js
    // ----------------------------------------------------
    const idFrontInput = document.getElementById('idFrontInput');
    const idBackInput = document.getElementById('idBackInput');

    idFrontInput.addEventListener('change', (e) => handleIdImageSelected(e, 'front'));
    idBackInput.addEventListener('change', (e) => handleIdImageSelected(e, 'back'));

    function handleIdImageSelected(event, side) {
      const file = event.target.files[0];
      if (!file) return;

      activeCropSide = side;
      document.getElementById('cropModalTitle').textContent = `Crop ${side === 'front' ? 'Front' : 'Back'} of ID Card`;

      const reader = new FileReader();
      reader.onload = (e) => {
        const image = document.getElementById('cropperImage');
        image.src = e.target.result;
        cropModal.show();

        if (cropperInstance) cropperInstance.destroy();
        cropperInstance = new Cropper(image, {
          aspectRatio: 1.586, // Standard ID Card Aspect Ratio
          viewMode: 1,
          autoCropArea: 0.9,
          responsive: true,
          restore: false
        });
      };
      reader.readAsDataURL(file);
      event.target.value = '';
    }

    function resetCrop() {
      if (cropperInstance) cropperInstance.reset();
    }

    function confirmCrop() {
      if (!cropperInstance) return;
      const canvas = cropperInstance.getCroppedCanvas({
        maxWidth: 1200,
        maxHeight: 800,
        fillColor: '#ffffff',
        imageSmoothingEnabled: true,
        imageSmoothingQuality: 'high'
      });

      const croppedDataUrl = canvas.toDataURL('image/jpeg', 0.92);

      if (activeCropSide === 'front') {
        idFrontBlob = croppedDataUrl;
        document.getElementById('idFrontData').value = croppedDataUrl;
        document.getElementById('idFrontPlaceholder').style.display = 'none';
        document.getElementById('idFrontPreviewWrapper').style.display = 'block';
        document.getElementById('idFrontPreviewImg').src = croppedDataUrl;
        document.getElementById('idFrontBox').classList.add('has-image');
        document.getElementById('a4SlotFront').src = croppedDataUrl;
      } else {
        idBackBlob = croppedDataUrl;
        document.getElementById('idBackData').value = croppedDataUrl;
        document.getElementById('idBackPlaceholder').style.display = 'none';
        document.getElementById('idBackPreviewWrapper').style.display = 'block';
        document.getElementById('idBackPreviewImg').src = croppedDataUrl;
        document.getElementById('idBackBox').classList.add('has-image');
        document.getElementById('a4SlotBack').src = croppedDataUrl;
      }

      cropModal.hide();

      // If both sides cropped, show A4 composite preview
      if (idFrontBlob && idBackBlob) {
        document.getElementById('idA4PreviewSection').style.display = 'block';
      }

      updateTotalPricing();
    }

    // ----------------------------------------------------
    // Price Calculation & Primary Button State
    // ----------------------------------------------------
    function calculateTotalPages() {
      if (currentMode === 'id_card') {
        return (idFrontBlob && idBackBlob) ? 1 : 0;
      } else {
        return selectedFiles.reduce((acc, f) => acc + (f.selectedPages ? f.selectedPages.length : 1), 0);
      }
    }

    function updateTotalPricing() {
      const pages = calculateTotalPages();
      const unitRate = rates[currentColorMode] || 2.00;
      const totalAmount = (pages * currentCopies * unitRate);

      const summaryText = `${pages} ${pages === 1 ? 'page' : 'pages'} × ${currentCopies} ${currentCopies === 1 ? 'copy' : 'copies'}`;
      document.getElementById('pricePagesSummary').textContent = summaryText;
      document.getElementById('totalPriceDisplay').textContent = `₹${totalAmount.toFixed(2)}`;

      const submitBtn = document.getElementById('btnMainSubmit');
      const submitText = document.getElementById('submitBtnText');

      if (pages === 0) {
        submitBtn.disabled = true;
        submitText.textContent = (currentMode === 'id_card') ? 'Upload front & back ID' : 'Select files to print';
      } else {
        submitBtn.disabled = false;
        if (currentPaymentMethod === 'CASH') {
          submitText.textContent = `Request Print & Pay at Counter (₹${totalAmount.toFixed(2)})`;
        } else {
          submitText.textContent = `Pay ₹${totalAmount.toFixed(2)} & Print`;
        }
      }
    }

    // ----------------------------------------------------
    // Form Submission (Instant Online or Cash Payment)
    // ----------------------------------------------------
    const form = document.getElementById('printOrderForm');
    form.addEventListener('submit', async (e) => {
      e.preventDefault();

      const totalPages = calculateTotalPages();
      if (totalPages <= 0) {
        alert(currentMode === 'id_card' ? 'Please upload and crop both front and back of your ID card.' : 'Please select at least one file to print.');
        return;
      }

      const submitBtn = document.getElementById('btnMainSubmit');
      const spinner = document.getElementById('submitBtnSpinner');
      const btnText = document.getElementById('submitBtnText');

      submitBtn.disabled = true;
      spinner.style.display = 'inline-block';
      btnText.textContent = 'Preparing order...';

      try {
        const formData = new FormData(form);

        // Append files for Regular Mode
        if (currentMode === 'regular') {
          // If customer selected specific pages on a single PDF
          if (selectedFiles.length === 1 && selectedFiles[0].isPdf && selectedFiles[0].selectedPages.length < selectedFiles[0].totalPages) {
            btnText.textContent = 'Preparing selected pages...';
            const fObj = selectedFiles[0];
            let extracted = false;

            if (typeof PDFLib !== 'undefined' && PDFLib.PDFDocument) {
              try {
                const sourceBytes = await fObj.file.arrayBuffer();
                const srcDoc = await PDFLib.PDFDocument.load(sourceBytes);
                const subDoc = await PDFLib.PDFDocument.create();
                const pageIndices = fObj.selectedPages.map(p => p - 1);
                const copiedPages = await subDoc.copyPages(srcDoc, pageIndices);
                copiedPages.forEach(p => subDoc.addPage(p));
                const subBytes = await subDoc.save();
                const extractedBlob = new Blob([subBytes], { type: 'application/pdf' });
                formData.delete('documents[]');
                formData.append('documents[]', extractedBlob, fObj.name);
                formData.set('selected_pages', fObj.selectedPages.join(','));
                extracted = true;
              } catch (exErr) {
                console.warn('Client extraction error, passing to server:', exErr);
              }
            }

            if (!extracted) {
              formData.delete('documents[]');
              formData.append('documents[]', fObj.file);
              formData.set('selected_pages', fObj.selectedPages.join(','));
            }
          } else {
            formData.delete('documents[]');
            selectedFiles.forEach(f => {
              formData.append('documents[]', f.file);
            });
            if (selectedFiles.length === 1 && selectedFiles[0].isPdf) {
              formData.set('selected_pages', selectedFiles[0].selectedPages.join(','));
            }
          }
        }

        btnText.textContent = (currentPaymentMethod === 'CASH') ? 'Creating cash request...' : 'Initializing payment gateway...';

        const res = await fetch(form.action, {
          method: 'POST',
          body: formData
        });

        const data = await res.json();

        if (!data.success) {
          throw new Error(data.error || 'Server error creating order.');
        }

        // Handle Cash Payment
        if (data.payment_method === 'CASH') {
          window.location.href = data.redirect_url;
          return;
        }

        // Handle Online Razorpay Payment
        if (data.order_id && window.Razorpay) {
          btnText.textContent = 'Opening gateway...';
          const rzpOptions = {
            key: data.key_id,
            amount: data.amount,
            currency: 'INR',
            name: data.shop_name || 'PrimePrint',
            description: `Print Order #${data.token}`,
            order_id: data.order_id,
            handler: function (response) {
              btnText.textContent = 'Verifying payment...';
              verifyOnlinePayment(data.token, response);
            },
            modal: {
              ondismiss: function () {
                submitBtn.disabled = false;
                spinner.style.display = 'none';
                updateTotalPricing();
              }
            },
            theme: {
              color: '#2563eb'
            }
          };
          const rzp = new Razorpay(rzpOptions);
          rzp.open();
        } else {
          window.location.href = data.redirect_url;
        }

      } catch (err) {
        alert(err.message || 'Error creating print order. Please try again.');
        submitBtn.disabled = false;
        spinner.style.display = 'none';
        updateTotalPricing();
      }
    });

    async function verifyOnlinePayment(token, rzpResponse) {
      try {
        const res = await fetch(`${appUrl}/api/payment/verify.php`, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            token: token,
            order_token: token,
            razorpay_order_id: rzpResponse.razorpay_order_id,
            razorpay_payment_id: rzpResponse.razorpay_payment_id,
            razorpay_signature: rzpResponse.razorpay_signature
          })
        });
        const result = await res.json();
        if (result.success) {
          window.location.href = `${appUrl}/customer/order-success.php?token=${encodeURIComponent(token)}`;
        } else {
          alert(result.error || 'Payment verification failed. Please contact counter staff.');
          window.location.href = `${appUrl}/customer/order-success.php?token=${encodeURIComponent(token)}`;
        }
      } catch (e) {
        window.location.href = `${appUrl}/customer/order-success.php?token=${encodeURIComponent(token)}`;
      }
    }

    // Helpers
    function formatBytes(bytes) {
      if (bytes === 0) return '0 B';
      const k = 1024;
      const sizes = ['B', 'KB', 'MB'];
      const i = Math.floor(Math.log(bytes) / Math.log(k));
      return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
    }

    function escapeHtml(str) {
      return str.replace(/[&<>'"]/g, tag => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        "'": '&#39;',
        '"': '&quot;'
      }[tag] || tag));
    }

    // Initial Pricing Setup
    updateTotalPricing();
  </script>

</body>
</html>
