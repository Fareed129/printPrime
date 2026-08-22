<?php
/**
 * PrimePrint Customer Upload & Printing Configuration Portal
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
        // Already processed -> redirect directly to the existing order review
        $existingToken = $_SESSION['submitted_form_tokens'][$formToken];
        header("Location: " . APP_URL . "/customer/review.php?token=" . urlencode($existingToken));
        exit;
    }

    if (empty($formToken) || !isset($_SESSION['active_form_tokens'][$formToken])) {
        $errors[] = 'Form session has expired or was already submitted. Please refresh and try again.';
    }

    $paperSize = trim($_POST['paper_size'] ?? '');
    $colorMode = trim($_POST['color_mode'] ?? '');
    $sideMode  = trim($_POST['side_mode'] ?? '');
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
                    // Images strictly count as 1 page
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
                            // Generate safe unique public order token
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

                            $jobId = (int)$db->lastInsertId();

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
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title><?= e($pageTitle) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
  <link rel="stylesheet" href="<?= APP_URL ?>/assets/css/style.css">
  <script>
    // Embed shop pricing rules for live browser calculations
    window.SHOP_PRICING_TABLE = <?= json_encode($shopPricing) ?>;
  </script>
</head>
<body class="bg-light">

  <!-- Mobile-First Hero Section -->
  <header class="customer-hero">
    <div class="container" style="max-width: 600px;">
      <span class="badge bg-white text-primary rounded-pill px-3 py-1 fw-bold text-uppercase mb-2 shadow-sm" style="font-size: 0.75rem;">
        <i class="bi bi-printer-fill me-1"></i> <?= e($shop['name']) ?>
      </span>
      <h2 class="fw-bold mb-1">Self-Service Document Printing</h2>
      <p class="mb-0 text-white-50 small">Upload your document, configure printing options, and review your order.</p>
    </div>
  </header>

  <!-- Upload & Preferences Container -->
  <main class="customer-container">
    
    <div class="card card-pp shadow-sm mb-4">
      <div class="card-body p-4">

        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger py-2 small mb-4" role="alert">
            <ul class="mb-0 ps-3">
              <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if (!$hasOnlinePrinter): ?>
          <div class="alert alert-warning py-3 small mb-4 d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle-fill fs-4 text-warning"></i>
            <div>
              <strong>No printer is currently available.</strong><br>
              All printers at this shop are currently offline. Please check with the counter staff or try again later.
            </div>
          </div>
        <?php endif; ?>

        <?php if (empty($shopPricing)): ?>
          <div class="alert alert-danger py-3 small mb-4">
            <i class="bi bi-x-circle-fill me-1"></i>
            This shop has not configured any active printing rates yet.
          </div>
        <?php endif; ?>

        <form method="POST" action="<?= APP_URL ?>/p/<?= e($shop['slug']) ?>" enctype="multipart/form-data" id="customerPrintForm">
          <?= csrf_field() ?>
          <input type="hidden" name="form_token" value="<?= e($currentFormToken) ?>">

          <!-- STEP 1: Upload Document -->
          <div class="mb-4">
            <div class="d-flex align-items-center justify-content-between mb-2">
              <label class="form-label fw-bold text-dark mb-0">
                <span class="badge bg-primary rounded-circle me-1">1</span> Select Document
              </label>
              <span class="small text-muted">Max 25 MB</span>
            </div>

            <div class="dropzone-upload" id="customerDropzone">
              <i class="bi bi-cloud-arrow-up-fill text-primary display-4 d-block mb-2"></i>
              <div class="fw-bold text-dark mb-1">Tap to browse or drop file here</div>
              <div class="small text-muted mb-2">Supported formats: PDF, JPG, JPEG, PNG</div>
              <button type="button" class="btn btn-outline-primary btn-sm px-3 rounded-pill">
                <i class="bi bi-folder2-open me-1"></i> Choose File
              </button>
              <input type="file" name="document" id="customerFileInput" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="d-none" required>
            </div>

            <!-- Selected File Information Display -->
            <div id="filePreviewBox" class="p-3 bg-light rounded border mt-3 d-none align-items-center justify-content-between">
              <div class="d-flex align-items-center gap-2 text-truncate">
                <i class="bi bi-file-earmark-check-fill text-success fs-2"></i>
                <div class="text-truncate">
                  <div class="fw-bold text-dark text-truncate" id="previewFileName">document.pdf</div>
                  <div class="small text-muted">
                    <span id="previewFileType">PDF</span> • <span id="previewFileSize">0 MB</span>
                  </div>
                </div>
              </div>
              <span class="badge bg-success-subtle text-success border border-success-subtle">Attached</span>
            </div>
          </div>

          <!-- STEP 2: Printing Preferences -->
          <div class="mb-4">
            <label class="form-label fw-bold text-dark mb-2">
              <span class="badge bg-primary rounded-circle me-1">2</span> Printing Preferences
            </label>

            <div class="row g-3">
              <!-- Paper Size -->
              <div class="col-6">
                <label class="form-label small fw-semibold text-secondary">Paper Size <span class="text-danger">*</span></label>
                <select name="paper_size" id="paperSizeSelect" class="form-select" required>
                  <?php if (empty($availablePaperSizes)): ?>
                    <option value="A4">A4</option>
                  <?php else: ?>
                    <?php foreach ($availablePaperSizes as $size): ?>
                      <option value="<?= e($size) ?>"><?= e($size) ?></option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>

              <!-- Color Mode -->
              <div class="col-6">
                <label class="form-label small fw-semibold text-secondary">Color Mode <span class="text-danger">*</span></label>
                <select name="color_mode" id="colorModeSelect" class="form-select" required>
                  <?php if (empty($availableColorModes)): ?>
                    <option value="BW">Black & White</option>
                    <option value="COLOR">Full Color</option>
                  <?php else: ?>
                    <?php foreach ($availableColorModes as $mode): ?>
                      <option value="<?= e($mode) ?>"><?= $mode === 'BW' ? 'Black & White' : 'Full Color' ?></option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>

              <!-- Side Mode -->
              <div class="col-6">
                <label class="form-label small fw-semibold text-secondary">Sides <span class="text-danger">*</span></label>
                <select name="side_mode" id="sideModeSelect" class="form-select" required>
                  <?php if (empty($availableSideModes)): ?>
                    <option value="single">Single Sided</option>
                    <option value="double">Double Sided</option>
                  <?php else: ?>
                    <?php foreach ($availableSideModes as $side): ?>
                      <option value="<?= e($side) ?>"><?= ucfirst(e($side)) ?> Sided</option>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </select>
              </div>

              <!-- Copies -->
              <div class="col-6">
                <label class="form-label small fw-semibold text-secondary">Copies <span class="text-danger">*</span></label>
                <input type="number" name="copies" id="copiesInput" value="1" min="1" max="100" class="form-control" required>
              </div>
            </div>
          </div>

          <!-- STEP 3: Select Printer -->
          <div class="mb-4">
            <label class="form-label fw-bold text-dark mb-2">
              <span class="badge bg-primary rounded-circle me-1">3</span> Select Counter Printer
            </label>

            <?php if (empty($printers)): ?>
              <div class="p-3 bg-light rounded text-center text-muted small">
                No physical printers are registered for this shop.
              </div>
            <?php else: ?>
              <div class="list-group">
                <?php foreach ($printers as $idx => $p): 
                  $isOnline = in_array($p['status'], ['online', 'idle']);
                ?>
                  <label class="list-group-item d-flex align-items-center justify-content-between p-3 <?= !$isOnline ? 'bg-light text-muted' : '' ?>" style="cursor: <?= $isOnline ? 'pointer' : 'not-allowed' ?>;">
                    <div class="d-flex align-items-center gap-3">
                      <input class="form-check-input flex-shrink-0" type="radio" name="printer_id" value="<?= $p['id'] ?>" <?= ($isOnline && ($idx === 0 || !isset($firstChecked))) ? ($firstChecked = true ? 'checked' : '') : '' ?> <?= !$isOnline ? 'disabled' : '' ?> required>
                      <div>
                        <div class="fw-semibold text-dark"><?= e($p['printer_name']) ?></div>
                        <div class="small text-muted font-monospace"><?= e($p['printer_identifier'] ?? 'Standard Spooler') ?></div>
                      </div>
                    </div>
                    <span class="badge-status <?= e($p['status']) ?>">
                      <?= ucfirst(e($p['status'])) ?>
                    </span>
                  </label>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>

          <!-- STEP 4: Live Estimated Price Summary -->
          <div class="pricing-preview-box mb-4">
            <div class="d-flex align-items-center justify-content-between mb-1">
              <span class="small text-muted">Configured Rate:</span>
              <span class="fw-semibold text-dark" id="liveUnitRateDisplay">Calculating...</span>
            </div>
            <div class="d-flex align-items-center justify-content-between">
              <div>
                <span class="fw-bold text-dark d-block">Estimated Total:</span>
                <span class="small text-muted" id="priceNote">Server will verify exact page count on submit</span>
              </div>
              <span class="fw-bold fs-4 text-primary" id="livePriceDisplay">₹0.00</span>
            </div>
          </div>

          <!-- Submit Order Button -->
          <button type="submit" id="btnSubmitOrder" class="btn btn-primary btn-lg w-100 py-3 fw-bold rounded-3 shadow-sm" <?= (!$hasOnlinePrinter || empty($shopPricing)) ? 'disabled' : 'disabled' ?>>
            <i class="bi bi-arrow-right-circle-fill me-2"></i> Review & Prepare Print Order
          </button>

        </form>

      </div>
    </div>

    <!-- Shop Information Footer -->
    <div class="text-center text-muted small">
      <div class="fw-semibold text-dark"><?= e($shop['name']) ?></div>
      <div><i class="bi bi-geo-alt me-1"></i><?= e($shop['address'] ?? 'Counter') ?> • <i class="bi bi-telephone me-1"></i><?= e($shop['phone']) ?></div>
      <div class="mt-2 text-secondary">&copy; <?= date('Y') ?> PrimePrint Cloud SaaS</div>
    </div>

  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
