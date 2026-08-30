<?php
/**
 * PrimePrint Customer - Order Status & Payment Receipt Confirmation (Live Tracker)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$token = trim($_GET['token'] ?? '');
$jobId = (int)($_GET['id'] ?? 0);

if (empty($token) && $jobId <= 0) {
    header("Location: " . APP_URL . "/login.php");
    exit;
}

$db = getDBConnection();
if (!empty($token)) {
    $stmt = $db->prepare("
        SELECT j.*, s.name AS shop_name, s.slug AS shop_slug, s.phone AS shop_phone, s.address AS shop_address, pr.printer_name 
        FROM print_jobs j 
        INNER JOIN shops s ON j.shop_id = s.id 
        LEFT JOIN printers pr ON j.printer_id = pr.id 
        WHERE j.public_token = :token 
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
} else {
    $stmt = $db->prepare("
        SELECT j.*, s.name AS shop_name, s.slug AS shop_slug, s.phone AS shop_phone, s.address AS shop_address, pr.printer_name 
        FROM print_jobs j 
        INNER JOIN shops s ON j.shop_id = s.id 
        LEFT JOIN printers pr ON j.printer_id = pr.id 
        WHERE j.id = :id 
        LIMIT 1
    ");
    $stmt->execute([':id' => $jobId]);
}
$job = $stmt->fetch();

if (!$job) {
    http_response_code(404);
    die("Error 404: Print job order not found.");
}

$isPaid = in_array($job['payment_status'], ['paid', 'completed'], true);
$pageTitle = 'Order Tracker #' . $job['public_token'] . ' — ' . APP_NAME;
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
</head>
<body class="customer-app-shell d-flex align-items-center justify-content-center" style="padding: 24px 12px 60px;">

  <div class="container" style="max-width: 520px;">
    
    <div class="customer-card text-center p-4 mb-4" id="mainStatusCard">
      
      <!-- Top Animated Status Icon -->
      <div id="statusIconBox" class="d-inline-flex align-items-center justify-content-center <?= $isPaid ? 'bg-success text-white' : 'bg-primary text-white' ?> rounded-circle mb-3 mx-auto shadow" style="width: 68px; height: 68px; font-size: 2.2rem;">
        <?php if ($isPaid): ?>
          <i class="bi bi-check-lg"></i>
        <?php else: ?>
          <div class="spinner-border text-white" role="status" style="width: 2rem; height: 2rem;"></div>
        <?php endif; ?>
      </div>

      <h3 class="fw-bold text-dark mb-1 fs-4" id="statusHeading">
        <?= $isPaid ? 'Order Confirmed ✓' : 'Confirming Payment...' ?>
      </h3>
      <p class="text-muted small mb-3" id="statusSubheading">
        <?= $isPaid 
            ? 'Your document is processing at <strong>' . e($job['shop_name']) . '</strong>.' 
            : 'Verifying payment with the gateway. Your receipt will update automatically.' ?>
      </p>

      <!-- Order ID Badge -->
      <div class="p-3 bg-light rounded-3 border mb-4">
        <span class="text-muted small d-block text-uppercase fw-semibold" style="font-size: 0.72rem; letter-spacing: 0.05em;">Order Token</span>
        <span class="font-heading fw-bold fs-2 text-primary tracking-wide"><?= e($job['public_token'] ?? '#' . $job['id']) ?></span>
      </div>

      <!-- Live Order Progress Timeline -->
      <div class="text-start mb-4">
        <div class="option-group-label mb-3"><i class="bi bi-broadcast me-1 text-primary"></i>Live Printing Progress</div>
        
        <ul class="status-timeline" id="statusTimeline">
          <!-- Step 1: Payment -->
          <li class="timeline-step <?= $isPaid ? 'completed' : 'active' ?>" id="stepPayment">
            <div class="timeline-bullet">
              <i class="bi <?= $isPaid ? 'bi-check-lg' : 'bi-credit-card' ?>"></i>
            </div>
            <div class="timeline-content">
              <div class="timeline-title">Payment Confirmed</div>
              <div class="timeline-desc">Instant digital verification & receipt generated</div>
            </div>
          </li>

          <!-- Step 2: Queue -->
          <li class="timeline-step <?= $isPaid ? ($job['status'] === 'QUEUED' ? 'active' : 'completed') : '' ?>" id="stepQueue">
            <div class="timeline-bullet">
              <i class="bi bi-cloud-check"></i>
            </div>
            <div class="timeline-content">
              <div class="timeline-title">Added to Cloud Queue</div>
              <div class="timeline-desc">Encrypted document sent to shop's print spooler</div>
            </div>
          </li>

          <!-- Step 3: Printing -->
          <li class="timeline-step <?= in_array($job['status'], ['DOWNLOADING', 'PRINTING'], true) ? 'active' : ($job['status'] === 'PRINTED' ? 'completed' : '') ?>" id="stepPrinting">
            <div class="timeline-bullet">
              <i class="bi bi-printer"></i>
            </div>
            <div class="timeline-content">
              <div class="timeline-title">Spooling & Printing</div>
              <div class="timeline-desc">Physical counter printer outputting pages</div>
            </div>
          </li>

          <!-- Step 4: Ready -->
          <li class="timeline-step <?= $job['status'] === 'PRINTED' ? 'completed' : '' ?>" id="stepReady">
            <div class="timeline-bullet">
              <i class="bi bi-box2-heart"></i>
            </div>
            <div class="timeline-content">
              <div class="timeline-title">Ready for Counter Pickup</div>
              <div class="timeline-desc">Collect your printed pages from the desk</div>
            </div>
          </li>
        </ul>
      </div>

      <!-- Job Summary Details List -->
      <div class="list-group list-group-flush text-start small mb-4 border rounded-3 p-2 bg-light">
        <div class="list-group-item bg-transparent d-flex justify-content-between px-2 py-2">
          <span class="text-muted">Document:</span>
          <span class="fw-semibold text-dark text-truncate" style="max-width: 230px;"><?= e($job['file_name']) ?></span>
        </div>
        <div class="list-group-item bg-transparent d-flex justify-content-between px-2 py-2">
          <span class="text-muted">Pages & Copies:</span>
          <span class="fw-semibold text-dark"><?= $job['page_count'] ?> pages × <?= $job['copies'] ?> <?= $job['copies'] > 1 ? 'copies' : 'copy' ?></span>
        </div>
        <div class="list-group-item bg-transparent d-flex justify-content-between px-2 py-2">
          <span class="text-muted">Target Printer:</span>
          <span class="fw-semibold text-dark"><?= e($job['printer_name'] ?? 'Counter Spooler') ?></span>
        </div>
        <div class="list-group-item bg-transparent d-flex justify-content-between px-2 py-2">
          <span class="text-muted">Format:</span>
          <span class="badge bg-white text-dark border"><?= e($job['paper_size']) ?> • <?= e($job['color_mode']) ?> • <?= e($job['side_mode']) ?></span>
        </div>
        <div class="list-group-item bg-transparent d-flex justify-content-between px-2 py-2 border-top">
          <span class="fw-bold text-dark">Amount Paid:</span>
          <span class="fw-bold fs-6 text-success"><?= format_currency($job['amount']) ?></span>
        </div>
      </div>

      <div class="d-grid gap-2">
        <a href="<?= APP_URL ?>/p/<?= e($job['shop_slug']) ?>" class="btn btn-primary fw-semibold py-3 rounded-3 shadow-sm">
          <i class="bi bi-plus-circle-fill me-2"></i> Print Another Document
        </a>
        <?php if (!empty($job['shop_phone'])): ?>
          <a href="tel:<?= e($job['shop_phone']) ?>" class="btn btn-outline-secondary btn-sm py-2 rounded-3">
            <i class="bi bi-telephone-fill me-1 text-success"></i> Call Shop (<?= e($job['shop_phone']) ?>)
          </a>
        <?php endif; ?>
      </div>

    </div>

    <div class="text-center text-muted small pb-4">
      <div>Need assistance? Visit counter at <strong><?= e($job['shop_name']) ?></strong></div>
      <div><?= e($job['shop_address'] ?? '') ?></div>
      <div class="mt-1 text-secondary">&copy; <?= date('Y') ?> PrimePrint Cloud SaaS</div>
    </div>

  </div>

  <script>
    const orderToken = <?= json_encode($job['public_token']) ?>;
    let isConfirmed = <?= $isPaid ? 'true' : 'false' ?>;

    // Asynchronous live polling to update timeline steps in real-time
    const pollInterval = setInterval(async () => {
      try {
        const res = await fetch(`/api/payment/status.php?token=${encodeURIComponent(orderToken)}`);
        if (res.ok) {
          const data = await res.json();
          if (data && data.success) {
            updateLiveTrackerUI(data);
          }
        }
      } catch (err) {
        console.warn('Status poll warning:', err);
      }
    }, 2500);

    function updateLiveTrackerUI(data) {
      if (data.is_confirmed) {
        document.getElementById('statusIconBox').className = 'd-inline-flex align-items-center justify-content-center bg-success text-white rounded-circle mb-3 mx-auto shadow';
        document.getElementById('statusIconBox').innerHTML = '<i class="bi bi-check-lg"></i>';
        
        const stepPay = document.getElementById('stepPayment');
        if (stepPay) stepPay.className = 'timeline-step completed';

        const stepQueue = document.getElementById('stepQueue');
        const stepPrint = document.getElementById('stepPrinting');
        const stepReady = document.getElementById('stepReady');

        if (data.job_status === 'QUEUED') {
          if (stepQueue) stepQueue.className = 'timeline-step active';
          document.getElementById('statusHeading').textContent = 'Queued for Printing ✓';
        } else if (data.job_status === 'DOWNLOADING' || data.job_status === 'PRINTING') {
          if (stepQueue) stepQueue.className = 'timeline-step completed';
          if (stepPrint) stepPrint.className = 'timeline-step active';
          document.getElementById('statusHeading').textContent = 'Printing Document... 🖨️';
        } else if (data.job_status === 'PRINTED' || data.job_status === 'COMPLETED') {
          if (stepQueue) stepQueue.className = 'timeline-step completed';
          if (stepPrint) stepPrint.className = 'timeline-step completed';
          if (stepReady) stepReady.className = 'timeline-step completed';
          document.getElementById('statusHeading').textContent = 'Printing Complete! 🎉';
          document.getElementById('statusSubheading').innerHTML = 'Your pages are ready for collection at the counter.';
          clearInterval(pollInterval);
        }
      }
    }
  </script>

  <!-- Bootstrap 5.3 JS Bundle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
