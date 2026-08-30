<?php
/**
 * PrimePrint Customer Upload & Printing Configuration Portal (Mobile-First Web App)
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

// 1. Fetch active pricing combinations for this shop
$stmt = $db->prepare("
    SELECT paper_size, color_mode, side_mode, price_per_page 
    FROM pricing 
    WHERE shop_id = :shop_id AND active = 1 
    ORDER BY paper_size, color_mode, side_mode
");
$stmt->execute([':shop_id' => $shop['id']]);
$shopPricing = $stmt->fetchAll();

// Extract unique configured options
$availablePaperSizes = array_values(array_unique(array_column($shopPricing, 'paper_size')));
$availableColorModes = array_values(array_unique(array_column($shopPricing, 'color_mode')));
$availableSideModes  = array_values(array_unique(array_column($shopPricing, 'side_mode')));

// 2. Fetch physical printers belonging strictly to this shop
$stmt = $db->prepare("
    SELECT id, printer_name, printer_identifier, status 
    FROM printers 
    WHERE shop_id = :shop_id 
    ORDER BY (status IN ('online', 'idle')) DESC, printer_name ASC
");
$stmt->execute([':shop_id' => $shop['id']]);
$printers = $stmt->fetchAll();

// Check if there is at least one online printer
$onlinePrinters = array_filter($printers, fn($p) => in_array($p['status'], ['online', 'idle']));
$hasOnlinePrinter = count($onlinePrinters) > 0;

$errors = [];

// Initialize session form token storage for duplicate submission protection
if (!isset($_SESSION['active_form_tokens'])) {
    $_SESSION['active_form_tokens'] = [];
}
if (!isset($_SESSION['submitted_form_tokens'])) {
    $_SESSION['submitted_form_tokens'] = [];
}

// Clean up expired form tokens older than 2 hours
$twoHoursAgo = time() - 7200;
foreach ($_SESSION['active_form_tokens'] as $tok => $ts) {
    if ($ts < $twoHoursAgo) unset($_SESSION['active_form_tokens'][$tok]);
}

// Handle Customer Order Submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $formToken = trim($_POST['form_token'] ?? '');

    // Check duplicate submission
    if (!empty($formToken) && isset($_SESSION['submitted_form_tokens'][$formToken])) {
        $existingToken = $_SESSION['submitted_form_tokens'][$formToken];
        header("Location: " . APP_URL . "/customer/review.php?token=" . urlencode($existingToken));
        exit;
    }

    if (empty($formToken) || !isset($_SESSION['active_form_tokens'][$formToken])) {
        $errors[] = 'Form session has expired or was already submitted. Please refresh and try again.';
    }

    $paperSize = trim($_POST['paper_size'] ?? 'A4');
    $colorMode = trim($_POST['color_mode'] ?? 'BW');
    $sideMode  = trim($_POST['side_mode'] ?? 'single');
    $copies    = (int)($_POST['copies'] ?? 1);
    $printerId = (int)($_POST['printer_id'] ?? 0);

    // Validate Copies (Min: 1, Max: 100)
    if ($copies < 1 || $copies > 100) {
        $errors[] = 'Copies must be a valid number between 1 and 100.';
    }

    // Validate Printer Selection (Server-Side Shop Isolation)
    if ($printerId <= 0) {
        $errors[] = 'Please select a printer for your order.';
    } else {
        $stmt = $db->prepare("SELECT id, printer_name, status FROM printers WHERE id = :id AND shop_id = :shop_id LIMIT 1");
        $stmt->execute([':id' => $printerId, ':shop_id' => $shop['id']]);
        $selectedPrinter = $stmt->fetch();

        if (!$selectedPrinter) {
            $errors[] = 'Invalid printer selected.';
        } elseif (!in_array($selectedPrinter['status'], ['online', 'idle'])) {
            $errors[] = 'The selected printer is currently offline. Please choose an online printer.';
        }
    }

    // Validate Document Upload
    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please choose a valid document to upload.';
    } else {
        $file = $_FILES['document'];
        $originalName = basename($file['name']);
        $fileSize = (int)$file['size'];
        $tmpPath = $file['tmp_name'];

        if ($fileSize <= 0) {
            $errors[] = 'Uploaded file is empty.';
        } elseif ($fileSize > MAX_FILE_SIZE_BYTES) {
            $errors[] = 'File size exceeds the maximum limit of 25 MB.';
        }

        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
            $errors[] = 'Only PDF, JPG, JPEG, and PNG files are supported.';
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        if (!in_array($mimeType, ALLOWED_MIME_TYPES, true)) {
            $errors[] = "Invalid file format ({$mimeType}). Please upload a valid document or image.";
        }

        if (empty($errors)) {
            // Generate secure randomized storage filename
            $storedFileName = bin2hex(random_bytes(16)) . '.' . $ext;
            $destination = UPLOAD_DIR . $storedFileName;

            if (!is_dir(UPLOAD_DIR)) {
                mkdir(UPLOAD_DIR, 0755, true);
            }

            if (!move_uploaded_file($tmpPath, $destination)) {
                $errors[] = 'Failed to securely store uploaded document. Please try again.';
            } else {
                // Server-Side Page Count Determination
                if ($ext === 'pdf' || str_contains($mimeType, 'pdf')) {
                    $detectedPages = detect_pdf_page_count($destination);
                    if ($detectedPages === false || $detectedPages <= 0) {
                        @unlink($destination);
                        $errors[] = 'Unable to safely determine document page count. Please ensure the PDF is not corrupted or password-protected.';
                    } else {
                        $serverPageCount = $detectedPages;
                    }
                } else {
                    $serverPageCount = 1;
                }

                if (empty($errors)) {
                    // Server-Side Price Calculation (NO FALLBACKS)
                    $priceResult = calculate_order_price($db, $shop['id'], $paperSize, $colorMode, $sideMode, $serverPageCount, $copies);

                    if (!$priceResult['success']) {
                        @unlink($destination);
                        $errors[] = $priceResult['error'];
                    } else {
                        $calculatedAmount = $priceResult['total_amount'];

                        try {
                            $publicToken = generate_public_order_token($db);

                            // Insert Print Job in PAYMENT_PENDING state
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
                                ':page_count'       => $serverPageCount,
                                ':copies'           => $copies,
                                ':paper_size'       => $paperSize,
                                ':color_mode'       => $colorMode,
                                ':side_mode'        => $sideMode,
                                ':amount'           => $calculatedAmount
                            ]);

                            // Mark form token as consumed to prevent double submissions
                            unset($_SESSION['active_form_tokens'][$formToken]);
                            $_SESSION['submitted_form_tokens'][$formToken] = $publicToken;

                            // Redirect to dedicated Review Page using public order token
                            header("Location: " . APP_URL . "/customer/review.php?token=" . urlencode($publicToken));
                            exit;

                        } catch (Exception $e) {
                            @unlink($destination);
                            error_log("Order creation error: " . $e->getMessage());
                            $errors[] = 'Unable to create print order. Please try again.';
                        }
                    }
                }
            }
        }
    }
}

// Generate fresh form token for this page render
$currentFormToken = bin2hex(random_bytes(16));
$_SESSION['active_form_tokens'][$currentFormToken] = time();

$pageTitle = 'Print at ' . $shop['name'] . ' — ' . APP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
  <title><?= e($pageTitle) ?></title>
  
  <!-- Bootstrap 5.3 CSS -->
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  
  <!-- Bootstrap Icons -->
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  
  <!-- Custom Modern Design System -->
  <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
  
  <script>
    // Embed shop pricing rules for instant client-side calculation
    window.SHOP_PRICING_TABLE = <?= json_encode($shopPricing) ?>;
  </script>
</head>
<body class="customer-app-shell">

  <!-- Mobile Hero Header -->
  <header class="customer-hero-header">
    <div class="container" style="max-width: 560px;">
      
      <div class="shop-counter-badge">
        <i class="bi bi-shop"></i>
        <span><?= e($shop['name']) ?></span>
      </div>

      <h2 class="fw-bold text-white mb-1 fs-3">Self-Service Counter Print</h2>
      <p class="text-white-50 small mb-0">Upload document, choose options, and print instantly.</p>

      <!-- 3-Step Progress Indicator -->
      <div class="customer-stepper">
        <div class="step-item active">
          <span class="step-num">1</span>
          <span>Upload</span>
        </div>
        <span class="step-divider"></span>
        <div class="step-item">
          <span class="step-num">2</span>
          <span>Options</span>
        </div>
        <span class="step-divider"></span>
        <div class="step-item">
          <span class="step-num">3</span>
          <span>Print</span>
        </div>
      </div>

    </div>
  </header>

  <!-- Main Customer App Container -->
  <main class="customer-portal-container">
    
    <div class="customer-card">

      <?php if (!empty($errors)): ?>
        <div class="alert alert-danger py-2 small mb-4 rounded-3 d-flex align-items-center gap-2" role="alert">
          <i class="bi bi-exclamation-circle-fill fs-5 flex-shrink-0"></i>
          <ul class="mb-0 ps-2">
            <?php foreach ($errors as $err): ?>
              <li><?= e($err) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if (!$hasOnlinePrinter): ?>
        <div class="alert alert-warning py-3 small mb-4 rounded-3 d-flex align-items-center gap-3">
          <i class="bi bi-exclamation-triangle-fill fs-3 text-warning flex-shrink-0"></i>
          <div>
            <strong>Printers are currently offline.</strong><br>
            Please check with the shop counter staff or try again in a few moments.
          </div>
        </div>
      <?php endif; ?>

      <?php if (empty($shopPricing)): ?>
        <div class="alert alert-danger py-3 small mb-4 rounded-3">
          <i class="bi bi-x-circle-fill me-1"></i>
          This shop has not configured any active printing rates yet.
        </div>
      <?php endif; ?>

      <form method="POST" action="<?= APP_URL ?>/p/<?= e($shop['slug']) ?>" enctype="multipart/form-data" id="customerPrintForm">
        <?= csrf_field() ?>
        <input type="hidden" name="form_token" value="<?= e($currentFormToken) ?>">

        <!-- STEP 1: Upload Document -->
        <div class="mb-4">
          <div class="step-heading">
            <span class="step-badge-icon">1</span>
            <span>Upload Your Document</span>
          </div>

          <div class="dropzone-modern" id="customerDropzone">
            <div class="dropzone-icon-circle">
              <i class="bi bi-cloud-arrow-up-fill"></i>
            </div>
            <div class="fw-bold text-dark mb-1 fs-6">Tap to Choose File or Drop Here</div>
            <div class="small text-muted mb-3">Supports PDF, JPG, JPEG, PNG (Max 25 MB)</div>
            <button type="button" class="btn btn-outline-primary btn-sm px-4 rounded-pill fw-semibold">
              <i class="bi bi-folder2-open me-1"></i> Browse File
            </button>
            <input type="file" name="document" id="customerFileInput" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="d-none" required>
          </div>

          <!-- Selected File Preview Card -->
          <div id="filePreviewBox" class="file-attached-card d-none">
            <div class="d-flex align-items-center gap-3 text-truncate">
              <div class="file-type-icon">
                <i class="bi bi-file-earmark-pdf-fill"></i>
              </div>
              <div class="text-truncate">
                <div class="fw-bold text-dark text-truncate" id="previewFileName">document.pdf</div>
                <div class="small text-muted">
                  <span class="badge bg-success-subtle text-success border border-success-subtle me-1" id="previewFileType">PDF</span>
                  <span id="previewFileSize">0.00 MB</span>
                </div>
              </div>
            </div>
            <button type="button" id="btnRemoveFile" class="btn btn-sm btn-light border text-danger rounded-circle p-2" title="Remove File">
              <i class="bi bi-trash3"></i>
            </button>
          </div>
        </div>

        <hr class="my-4 text-muted opacity-25">

        <!-- STEP 2: Printing Preferences (Touch-Friendly Segmented Pills) -->
        <div class="mb-4">
          <div class="step-heading">
            <span class="step-badge-icon">2</span>
            <span>Choose Print Specifications</span>
          </div>

          <!-- Paper Size -->
          <div class="mb-3">
            <label class="option-group-label">Paper Size</label>
            <div class="touch-pill-grid <?= count($availablePaperSizes) > 2 ? 'grid-3' : '' ?>">
              <?php 
                $defaultSizes = !empty($availablePaperSizes) ? $availablePaperSizes : ['A4'];
                foreach ($defaultSizes as $idx => $size): 
              ?>
                <label class="touch-pill-option">
                  <input type="radio" name="paper_size" value="<?= e($size) ?>" <?= $idx === 0 ? 'checked' : '' ?>>
                  <div class="touch-pill-card">
                    <span class="pill-icon"><i class="bi bi-file-earmark"></i></span>
                    <span class="pill-title"><?= e($size) ?></span>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>
          </div>

          <!-- Color Mode -->
          <div class="mb-3">
            <label class="option-group-label">Color Mode</label>
            <div class="touch-pill-grid">
              <label class="touch-pill-option">
                <input type="radio" name="color_mode" value="BW" checked>
                <div class="touch-pill-card">
                  <span class="pill-icon"><i class="bi bi-circle-half"></i></span>
                  <span class="pill-title">Black & White</span>
                  <span class="pill-sub">Standard</span>
                </div>
              </label>

              <label class="touch-pill-option">
                <input type="radio" name="color_mode" value="COLOR">
                <div class="touch-pill-card">
                  <span class="pill-icon text-warning"><i class="bi bi-palette-fill"></i></span>
                  <span class="pill-title">Full Color</span>
                  <span class="pill-sub">Vibrant</span>
                </div>
              </label>
            </div>
          </div>

          <!-- Sides Mode & Copies Row -->
          <div class="row g-3">
            <div class="col-6">
              <label class="option-group-label">Sides</label>
              <div class="d-flex flex-column gap-2">
                <label class="touch-pill-option">
                  <input type="radio" name="side_mode" value="single" checked>
                  <div class="touch-pill-card">
                    <span class="pill-title">Single Sided</span>
                  </div>
                </label>
                <label class="touch-pill-option">
                  <input type="radio" name="side_mode" value="double">
                  <div class="touch-pill-card">
                    <span class="pill-title">Double Sided</span>
                  </div>
                </label>
              </div>
            </div>

            <div class="col-6">
              <label class="option-group-label">Number of Copies</label>
              <div class="stepper-copies-box">
                <button type="button" class="stepper-btn" id="btnMinusCopies">-</button>
                <input type="number" name="copies" id="copiesInput" value="1" min="1" max="100" class="stepper-input" required>
                <button type="button" class="stepper-btn" id="btnPlusCopies">+</button>
              </div>
              <div class="form-text small text-muted text-center mt-1">1 to 100 sets</div>
            </div>
          </div>
        </div>

        <hr class="my-4 text-muted opacity-25">

        <!-- STEP 3: Printer Selection -->
        <div class="mb-4">
          <div class="step-heading">
            <span class="step-badge-icon">3</span>
            <span>Target Counter Printer</span>
          </div>

          <?php if (empty($printers)): ?>
            <div class="p-3 bg-light rounded text-center text-muted small">
              No printers are currently registered for this shop.
            </div>
          <?php else: ?>
            <div class="printer-card-list">
              <?php foreach ($printers as $idx => $p): 
                $isOnline = in_array($p['status'], ['online', 'idle']);
              ?>
                <label class="printer-select-label">
                  <input type="radio" name="printer_id" value="<?= $p['id'] ?>" 
                         <?= ($isOnline && ($idx === 0 || !isset($firstSelectedPrinter))) ? ($firstSelectedPrinter = true ? 'checked' : '') : '' ?> 
                         <?= !$isOnline ? 'disabled' : '' ?> required>
                  <div class="printer-card <?= !$isOnline ? 'opacity-50' : '' ?>">
                    <div class="d-flex align-items-center gap-3">
                      <i class="bi bi-printer-fill fs-4 text-primary"></i>
                      <div>
                        <div class="fw-bold text-dark"><?= e($p['printer_name']) ?></div>
                        <div class="small text-muted font-monospace"><?= e($p['printer_identifier'] ?? 'Main Hardware Spooler') ?></div>
                      </div>
                    </div>
                    <div>
                      <?php if ($isOnline): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 small d-flex align-items-center gap-1">
                          <span class="pulse-dot"></span> Ready
                        </span>
                      <?php else: ?>
                        <span class="badge bg-secondary-subtle text-secondary border px-2 py-1 small">Offline</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </label>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

        <!-- Price Breakdown Card -->
        <div class="price-breakdown-card mb-4">
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="small text-muted fw-semibold">Unit Rate:</span>
            <span class="fw-bold text-dark" id="liveUnitRateDisplay">Calculating...</span>
          </div>
          <div class="d-flex align-items-center justify-content-between pt-2 border-top">
            <div>
              <span class="fw-bold text-dark fs-6 d-block">Estimated Total:</span>
              <span class="small text-muted">Verified by server on upload</span>
            </div>
            <span class="fw-bold fs-3 text-primary" id="livePriceDisplay">₹0.00</span>
          </div>
        </div>

        <!-- Inline Submit Button (for Desktop / Tablets) -->
        <div class="d-none d-sm-block">
          <button type="submit" id="btnSubmitOrder" class="btn btn-primary btn-lg w-100 py-3 fw-bold rounded-3 shadow-sm" disabled>
            <i class="bi bi-arrow-right-circle-fill me-2"></i> Review & Proceed to Payment
          </button>
        </div>

      </form>
    </div>

    <!-- Shop Counter Footer -->
    <div class="text-center text-muted small pb-4">
      <div class="fw-semibold text-dark"><?= e($shop['name']) ?></div>
      <div><i class="bi bi-geo-alt me-1"></i><?= e($shop['address'] ?? 'Shop Counter') ?> • <i class="bi bi-telephone me-1"></i><?= e($shop['phone']) ?></div>
      <div class="mt-2 text-secondary">&copy; <?= date('Y') ?> PrimePrint Cloud SaaS</div>
    </div>

  </main>

  <!-- Mobile Sticky Bottom Action Bar -->
  <div class="sticky-mobile-bar d-sm-none">
    <div class="sticky-mobile-bar-inner">
      <div class="sticky-price-display">
        <span class="price-label">Estimated Total</span>
        <span class="price-amount" id="stickyPriceDisplay">₹0.00</span>
      </div>
      <button type="button" id="btnSubmitOrderSticky" class="btn-checkout-sticky" disabled>
        <span>Review Order</span>
        <i class="bi bi-arrow-right-short fs-5"></i>
      </button>
    </div>
  </div>

  <!-- Bootstrap 5.3 JS Bundle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <!-- Custom App JS -->
  <script src="<?= asset_url('assets/js/app.js') ?>"></script>
</body>
</html>
