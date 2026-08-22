<?php
/**
 * PrimePrint Customer Upload & Ordering Page
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

// Fetch active pricing for this shop to populate client-side pricing matrix
$stmt = $db->prepare("SELECT paper_size, color_mode, side_mode, price_per_page FROM pricing WHERE shop_id = :shop_id AND active = 1");
$stmt->execute([':shop_id' => $shop['id']]);
$shopPricing = $stmt->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $paperSize = in_array($_POST['paper_size'] ?? '', ['A4', 'A3', 'Legal']) ? $_POST['paper_size'] : 'A4';
    $colorMode = in_array($_POST['color_mode'] ?? '', ['BW', 'COLOR']) ? $_POST['color_mode'] : 'BW';
    $sideMode  = in_array($_POST['side_mode'] ?? '', ['single', 'double']) ? $_POST['side_mode'] : 'single';
    $pageCount = max(1, (int)($_POST['page_count'] ?? 1));
    $copies    = max(1, (int)($_POST['copies'] ?? 1));

    // File Upload Validation
    if (!isset($_FILES['document']) || $_FILES['document']['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Please select a document to upload.';
    } else {
        $file = $_FILES['document'];
        $originalName = basename($file['name']);
        $fileSize = $file['size'];
        $tmpPath = $file['tmp_name'];

        // Size check
        if ($fileSize > MAX_FILE_SIZE_BYTES) {
            $errors[] = 'File size exceeds 25 MB limit.';
        }

        // Extension check
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        if (!in_array($ext, ALLOWED_EXTENSIONS, true)) {
            $errors[] = 'Only PDF, JPG, JPEG, and PNG files are supported.';
        }

        // MIME type check
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $mimeType = finfo_file($finfo, $tmpPath);
        finfo_close($finfo);

        if (!in_array($mimeType, ALLOWED_MIME_TYPES, true)) {
            $errors[] = "Invalid file type ({$mimeType}). Please upload a valid PDF or Image.";
        }

        if (empty($errors)) {
            // Generate randomized storage filename
            $storedFileName = bin2hex(random_bytes(16)) . '.' . $ext;
            $destination = UPLOAD_DIR . $storedFileName;

            if (!is_dir(UPLOAD_DIR)) {
                mkdir(UPLOAD_DIR, 0755, true);
            }

            if (!move_uploaded_file($tmpPath, $destination)) {
                $errors[] = 'Failed to securely store uploaded file. Please try again.';
            } else {
                try {
                    // SERVER-SIDE PRICE CALCULATION (Never trust client sent prices)
                    $priceResult = calculate_order_price($db, $shop['id'], $paperSize, $colorMode, $sideMode, $pageCount, $copies);
                    $calculatedAmount = $priceResult['total_amount'];

                    // Insert Print Job
                    $stmt = $db->prepare("
                        INSERT INTO print_jobs (
                            shop_id, file_name, stored_file_name, file_path, file_type, 
                            page_count, copies, paper_size, color_mode, side_mode, 
                            amount, status, payment_status
                        ) VALUES (
                            :shop_id, :file_name, :stored_file_name, :file_path, :file_type, 
                            :page_count, :copies, :paper_size, :color_mode, :side_mode, 
                            :amount, 'PAYMENT_PENDING', 'pending'
                        )
                    ");

                    $stmt->execute([
                        ':shop_id'          => $shop['id'],
                        ':file_name'        => $originalName,
                        ':stored_file_name' => $storedFileName,
                        ':file_path'        => $destination,
                        ':file_type'        => $mimeType,
                        ':page_count'       => $pageCount,
                        ':copies'           => $copies,
                        ':paper_size'       => $paperSize,
                        ':color_mode'       => $colorMode,
                        ':side_mode'        => $sideMode,
                        ':amount'           => $calculatedAmount
                    ]);

                    $jobId = (int)$db->lastInsertId();

                    // Insert Payment Placeholder
                    $stmt = $db->prepare("
                        INSERT INTO payments (job_id, shop_id, razorpay_order_id, amount, status)
                        VALUES (:job_id, :shop_id, :order_id, :amount, 'created')
                    ");
                    $stmt->execute([
                        ':job_id'   => $jobId,
                        ':shop_id'  => $shop['id'],
                        ':order_id' => 'ORD_' . strtoupper(bin2hex(random_bytes(6))),
                        ':amount'   => $calculatedAmount
                    ]);

                    header("Location: " . APP_URL . "/customer/order-success.php?id=" . $jobId);
                    exit;

                } catch (Exception $e) {
                    $errors[] = 'Database error creating order: ' . $e->getMessage();
                }
            }
        }
    }
}

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
    <div class="container" style="max-width: 580px;">
      <span class="badge bg-white text-primary rounded-pill px-3 py-1 fw-bold text-uppercase mb-2 shadow-sm" style="font-size: 0.75rem;">
        <i class="bi bi-printer-fill me-1"></i> <?= e($shop['name']) ?>
      </span>
      <h2 class="fw-bold mb-2">Print Your Documents</h2>
      <p class="mb-0 text-white-50 small">Upload your document, select printing preferences and pay online.</p>
    </div>
  </header>

  <!-- Upload & Preferences Form Container -->
  <main class="customer-container">
    
    <div class="card card-pp shadow-sm mb-4">
      <div class="card-body p-4">

        <?php if (!empty($errors)): ?>
          <div class="alert alert-danger py-2 small" role="alert">
            <ul class="mb-0 ps-3">
              <?php foreach ($errors as $err): ?>
                <li><?= e($err) ?></li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <form method="POST" action="<?= APP_URL ?>/p/<?= e($shop['slug']) ?>" enctype="multipart/form-data" id="customerPrintForm">
          <?= csrf_field() ?>

          <!-- Step 1: Upload Document -->
          <div class="mb-4">
            <label class="form-label fw-bold text-dark mb-2">
              <span class="badge bg-primary rounded-circle me-1">1</span> Select Document
            </label>

            <div class="dropzone-upload" id="customerDropzone">
              <i class="bi bi-cloud-arrow-up-fill text-primary display-4 d-block mb-2"></i>
              <div class="fw-bold text-dark mb-1">Tap to browse or drop file here</div>
              <div class="small text-muted mb-2">Supported: PDF, JPG, JPEG, PNG (Max 25MB)</div>
              <button type="button" class="btn btn-outline-primary btn-sm px-3 rounded-pill">
                <i class="bi bi-folder2-open me-1"></i> Choose File
              </button>
              <input type="file" name="document" id="customerFileInput" accept=".pdf,.jpg,.jpeg,.png,application/pdf,image/jpeg,image/png" class="d-none" required>
            </div>

            <!-- Selected File Preview -->
            <div id="filePreviewBox" class="p-3 bg-light rounded border mt-3 d-none align-items-center justify-content-between">
              <div class="d-flex align-items-center gap-2 text-truncate">
                <i class="bi bi-file-earmark-check-fill text-success fs-3"></i>
                <div class="text-truncate">
                  <div class="fw-bold text-dark text-truncate" id="previewFileName">document.pdf</div>
                  <div class="small text-muted" id="previewFileSize">0 MB</div>
                </div>
              </div>
              <span class="badge bg-success-subtle text-success border border-success-subtle">Ready</span>
            </div>
          </div>

          <!-- Step 2: Printing Preferences -->
          <div class="mb-4">
            <label class="form-label fw-bold text-dark mb-2">
              <span class="badge bg-primary rounded-circle me-1">2</span> Printing Preferences
            </label>

            <div class="row g-3">
              <div class="col-6">
                <label class="form-label small fw-semibold text-secondary">Paper Size</label>
                <select name="paper_size" id="paperSizeSelect" class="form-select">
                  <option value="A4" selected>A4 (Standard)</option>
                  <option value="A3">A3 (Large)</option>
                  <option value="Legal">Legal</option>
                </select>
              </div>

              <div class="col-6">
                <label class="form-label small fw-semibold text-secondary">Color Mode</label>
                <select name="color_mode" id="colorModeSelect" class="form-select">
                  <option value="BW" selected>Black & White</option>
                  <option value="COLOR">Full Color</option>
                </select>
              </div>

              <div class="col-12">
                <label class="form-label small fw-semibold text-secondary">Sides / Layout</label>
                <select name="side_mode" id="sideModeSelect" class="form-select">
                  <option value="single" selected>Single Sided (Front only)</option>
                  <option value="double">Double Sided (Back-to-Back)</option>
                </select>
              </div>

              <div class="col-6">
                <label class="form-label small fw-semibold text-secondary">Total Pages</label>
                <input type="number" name="page_count" id="pageCountInput" value="1" min="1" max="1000" class="form-control" required>
              </div>

              <div class="col-6">
                <label class="form-label small fw-semibold text-secondary">Copies</label>
                <input type="number" name="copies" id="copiesInput" value="1" min="1" max="100" class="form-control" required>
              </div>
            </div>
          </div>

          <!-- Step 3: Estimated Price Summary -->
          <div class="pricing-preview-box mb-4">
            <div class="d-flex align-items-center justify-content-between mb-1">
              <span class="small text-muted">Applicable Rate:</span>
              <span class="fw-semibold text-dark" id="liveUnitRateDisplay">₹2.00 / page</span>
            </div>
            <div class="d-flex align-items-center justify-content-between">
              <span class="fw-bold text-dark">Estimated Total:</span>
              <span class="fw-bold fs-4 text-primary" id="livePriceDisplay">₹2.00</span>
            </div>
          </div>

          <!-- Submit Button -->
          <button type="submit" id="btnSubmitOrder" class="btn btn-primary btn-lg w-100 py-3 fw-bold rounded-3 shadow-sm" disabled>
            <i class="bi bi-printer-fill me-2"></i> Submit & Proceed to Print
          </button>

        </form>

      </div>
    </div>

    <!-- Shop Footer Card -->
    <div class="text-center text-muted small">
      <div class="fw-semibold text-dark"><?= e($shop['name']) ?></div>
      <div><i class="bi bi-geo-alt me-1"></i><?= e($shop['address'] ?? 'Shop Center') ?> • <i class="bi bi-telephone me-1"></i><?= e($shop['phone']) ?></div>
      <div class="mt-2 text-secondary">&copy; <?= date('Y') ?> PrimePrint Cloud</div>
    </div>

  </main>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
  <script src="<?= APP_URL ?>/assets/js/app.js"></script>
</body>
</html>
