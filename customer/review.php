<?php
/**
 * PrimePrint Customer - Dedicated Order Review & Payment Preparation Page
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$token = trim($_GET['token'] ?? '');

if (empty($token)) {
    http_response_code(404);
    die("Error 404: No order token specified. Please start your order from the shop page.");
}

$db = getDBConnection();
$stmt = $db->prepare("
    SELECT j.*, s.name AS shop_name, s.slug AS shop_slug, s.phone AS shop_phone, s.address AS shop_address, p.printer_name, p.status AS printer_status 
    FROM print_jobs j 
    INNER JOIN shops s ON j.shop_id = s.id 
    LEFT JOIN printers p ON j.printer_id = p.id 
    WHERE j.public_token = :token 
    LIMIT 1
");
$stmt->execute([':token' => $token]);
$job = $stmt->fetch();

if (!$job) {
    http_response_code(404);
    die("Error 404: Print order token '{$token}' not found or has expired.");
}

$pageTitle = 'Review Order #' . $job['public_token'] . ' — ' . e($job['shop_name']);
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
</head>
<body class="bg-light d-flex align-items-center justify-content-center" style="min-height: 100vh; padding: 25px 12px;">

  <div class="container" style="max-width: 540px;">
    
    <div class="card card-pp shadow-sm p-4 mb-3">
      
      <!-- Review Header -->
      <div class="text-center mb-4">
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1 fw-bold text-uppercase mb-2" style="font-size: 0.75rem;">
          <i class="bi bi-shop me-1"></i> <?= e($job['shop_name']) ?>
        </span>
        <h4 class="fw-bold text-dark mb-1">Order Review</h4>
        <p class="text-muted small mb-0">Please verify your document details and printing specifications.</p>
      </div>

      <!-- Public Order Token Badge -->
      <div class="p-3 bg-light rounded-3 border text-center mb-4">
        <span class="text-muted small d-block text-uppercase fw-semibold">Public Order Token</span>
        <span class="fw-mono fw-bold fs-3 text-primary"><?= e($job['public_token']) ?></span>
      </div>

      <!-- Specifications Breakdown List -->
      <div class="list-group list-group-flush small mb-4">
        
        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
          <span class="text-muted"><i class="bi bi-file-earmark-text me-1"></i> Document:</span>
          <span class="fw-semibold text-dark text-truncate" style="max-width: 260px;" title="<?= e($job['file_name']) ?>">
            <?= e($job['file_name']) ?>
          </span>
        </div>

        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
          <span class="text-muted"><i class="bi bi-file-earmark-break me-1"></i> Pages Verified:</span>
          <span class="fw-bold text-dark badge bg-light text-secondary border fs-6">
            <?= $job['page_count'] ?> <?= $job['page_count'] > 1 ? 'pages' : 'page' ?>
          </span>
        </div>

        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
          <span class="text-muted"><i class="bi bi-printer me-1"></i> Target Printer:</span>
          <span class="fw-semibold text-dark d-flex align-items-center gap-1">
            <?= e($job['printer_name'] ?? 'Counter Spooler') ?>
            <span class="badge-status <?= e($job['printer_status'] ?? 'online') ?> ms-1" style="font-size: 0.7rem;">
              <?= ucfirst(e($job['printer_status'] ?? 'online')) ?>
            </span>
          </span>
        </div>

        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
          <span class="text-muted"><i class="bi bi-aspect-ratio me-1"></i> Paper Size:</span>
          <span class="fw-semibold text-dark"><?= e($job['paper_size']) ?></span>
        </div>

        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
          <span class="text-muted"><i class="bi bi-palette me-1"></i> Color Mode:</span>
          <span class="fw-semibold text-dark"><?= $job['color_mode'] === 'COLOR' ? 'Full Color' : 'Black & White' ?></span>
        </div>

        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
          <span class="text-muted"><i class="bi bi-layers me-1"></i> Sides:</span>
          <span class="fw-semibold text-dark"><?= ucfirst(e($job['side_mode'])) ?> Sided</span>
        </div>

        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2">
          <span class="text-muted"><i class="bi bi-copy me-1"></i> Copies:</span>
          <span class="fw-semibold text-dark"><?= $job['copies'] ?> <?= $job['copies'] > 1 ? 'copies' : 'copy' ?></span>
        </div>

        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-3 mt-1 border-top border-2">
          <div>
            <span class="fw-bold text-dark fs-6 d-block">Total Amount:</span>
            <span class="small text-muted">(<?= $job['page_count'] ?> pgs × <?= $job['copies'] ?> copies)</span>
          </div>
          <span class="fw-bold fs-3 text-success"><?= format_currency($job['amount']) ?></span>
        </div>

      </div>

      <!-- Payment Status Alert -->
      <div class="alert alert-warning py-2 px-3 small d-flex align-items-center gap-2 mb-4">
        <i class="bi bi-hourglass-split fs-5 text-warning flex-shrink-0"></i>
        <div>
          <strong>Status: Payment Pending</strong><br>
          Your document is securely registered. Proceed to initiate online checkout or present token <strong><?= e($job['public_token']) ?></strong> at the counter.
        </div>
      </div>

      <!-- Proceed to Payment Action Button -->
      <div class="d-grid gap-2">
        <button type="button" class="btn btn-primary btn-lg fw-bold py-3 shadow-sm" data-bs-toggle="modal" data-bs-target="#paymentModal">
          <i class="bi bi-credit-card-2-front-fill me-2"></i> Proceed to Payment (<?= format_currency($job['amount']) ?>)
        </button>
        <a href="<?= APP_URL ?>/p/<?= e($job['shop_slug']) ?>" class="btn btn-outline-secondary btn-sm py-2">
          <i class="bi bi-plus-circle me-1"></i> Upload Another Document
        </a>
      </div>

    </div>

    <!-- Shop Footer -->
    <div class="text-center text-muted small">
      <div><?= e($job['shop_name']) ?> • <i class="bi bi-telephone me-1"></i><?= e($job['shop_phone']) ?></div>
      <div class="mt-1">&copy; <?= date('Y') ?> PrimePrint Cloud SaaS</div>
    </div>

  </div>

  <!-- Modal: Payment Gateway Readiness Notice -->
  <div class="modal fade" id="paymentModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header border-0 pb-0">
          <h5 class="modal-title fw-bold text-dark">Payment Gateway Initialization</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body text-center py-4">
          <div class="d-inline-flex align-items-center justify-content-center bg-primary-subtle text-primary rounded-circle mb-3 p-3" style="width: 60px; height: 60px;">
            <i class="bi bi-shield-lock fs-2"></i>
          </div>
          <h5 class="fw-bold text-dark mb-2">Ready for Razorpay Integration</h5>
          <p class="small text-muted mb-3">
            Your print order <strong><?= e($job['public_token']) ?></strong> for <strong><?= format_currency($job['amount']) ?></strong> is active in the shop queue.
          </p>
          <div class="p-3 bg-light rounded text-start small border mb-3">
            <div class="fw-semibold text-dark mb-1"><i class="bi bi-info-circle text-primary me-1"></i> Phase 2 Configuration Status:</div>
            <div>• Server-side price calculation: <strong>Verified</strong></div>
            <div>• Document integrity & page count: <strong>Verified</strong></div>
            <div>• Printer hardware assignment: <strong><?= e($job['printer_name'] ?? 'Assigned') ?></strong></div>
            <div>• Payment Gateway: <strong>Razorpay (Phase 3)</strong></div>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-secondary w-100" data-bs-dismiss="modal">Close</button>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
