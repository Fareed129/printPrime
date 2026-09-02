<?php
/**
 * PrimePrint Customer - Order Status & Live Printing Progress Tracker (V2)
 * Handles: Online Paid, Cash Pending Approval, Cash Accepted, and Cash Rejected states.
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
$isCash = ($job['payment_method'] === 'CASH');
$isCashPending = ($isCash && $job['payment_status'] === 'pending_cash');
$isRejected = ($job['status'] === 'REJECTED' || $job['payment_status'] === 'rejected');

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
      
      <!-- Top Dynamic Status Icon -->
      <div id="statusIconBox" class="d-inline-flex align-items-center justify-content-center rounded-circle mb-3 mx-auto shadow <?= $isRejected ? 'bg-danger text-white' : ($isCashPending ? 'bg-warning text-dark' : ($isPaid ? 'bg-success text-white' : 'bg-primary text-white')) ?>" style="width: 72px; height: 72px; font-size: 2.2rem;">
        <?php if ($isRejected): ?>
          <i class="bi bi-x-lg"></i>
        <?php elseif ($isCashPending): ?>
          <i class="bi bi-cash-stack"></i>
        <?php elseif ($isPaid): ?>
          <i class="bi bi-check-lg"></i>
        <?php else: ?>
          <div class="spinner-border text-white" role="status" style="width: 2rem; height: 2rem;"></div>
        <?php endif; ?>
      </div>

      <!-- Headings -->
      <h3 class="fw-bold text-dark mb-1 fs-4" id="statusHeading">
        <?php if ($isRejected): ?>
          Print Request Rejected
        <?php elseif ($isCashPending): ?>
          Waiting for shop approval
        <?php elseif ($isPaid): ?>
          Payment Accepted ✓
        <?php else: ?>
          Confirming Payment...
        <?php endif; ?>
      </h3>

      <p class="text-muted small mb-3" id="statusSubheading">
        <?php if ($isRejected): ?>
          Print request was not accepted by the shop.
          <?php if (!empty($job['cash_rejection_reason'])): ?>
            <br><span class="text-danger fw-semibold">Reason: <?= e($job['cash_rejection_reason']) ?></span>
          <?php endif; ?>
        <?php elseif ($isCashPending): ?>
          Please pay <strong><?= format_currency($job['amount']) ?></strong> at the counter.<br>
          <span class="text-dark fw-semibold">Your document will be printed after the shop confirms payment.</span>
        <?php elseif ($isPaid): ?>
          Your document is processing at <strong><?= e($job['shop_name']) ?></strong>.
        <?php else: ?>
          Verifying payment with the gateway. Your receipt will update automatically.
        <?php endif; ?>
      </p>

      <!-- Order ID & Amount Callout Badge -->
      <div class="p-3 bg-light rounded-3 border mb-4">
        <div class="row align-items-center">
          <div class="col-7 text-start border-end">
            <span class="text-muted small d-block text-uppercase fw-semibold" style="font-size: 0.7rem; letter-spacing: 0.05em;">Order Token</span>
            <span class="font-heading fw-bold fs-3 text-primary tracking-wide"><?= e($job['public_token'] ?? '#' . $job['id']) ?></span>
          </div>
          <div class="col-5 text-end">
            <span class="text-muted small d-block text-uppercase fw-semibold" style="font-size: 0.7rem; letter-spacing: 0.05em;">Amount to Pay</span>
            <span class="fw-bold fs-4 <?= $isPaid ? 'text-success' : 'text-dark' ?>"><?= format_currency($job['amount']) ?></span>
          </div>
        </div>
      </div>

      <!-- Order Details Pills -->
      <div class="d-flex flex-wrap justify-content-center gap-2 mb-4">
        <span class="badge bg-light text-secondary border px-2 py-1">
          <i class="bi bi-file-earmark me-1"></i><?= !empty($job['is_id_card']) ? 'ID Card (A4)' : e($job['file_name']) ?>
        </span>
        <span class="badge bg-light text-secondary border px-2 py-1">
          <i class="bi bi-files me-1"></i><?= $job['page_count'] ?> <?= $job['page_count'] > 1 ? 'pages' : 'page' ?>
        </span>
        <span class="badge bg-light text-secondary border px-2 py-1">
          <i class="bi bi-copy me-1"></i><?= $job['copies'] ?> <?= $job['copies'] > 1 ? 'copies' : 'copy' ?>
        </span>
        <span class="badge bg-light text-secondary border px-2 py-1">
          <i class="bi bi-palette me-1"></i><?= $job['color_mode'] === 'COLOR' ? 'Color' : 'B&W' ?>
        </span>
      </div>

      <!-- Live Order Progress Timeline (hidden if rejected) -->
      <div class="text-start mb-4" id="timelineSection" style="<?= $isRejected ? 'display:none;' : '' ?>">
        <div class="option-group-label mb-3"><i class="bi bi-broadcast me-1 text-primary"></i>Live Printing Progress</div>
        
        <ul class="status-timeline" id="statusTimeline">
          <!-- Step 1: Payment Approval -->
          <li class="timeline-step <?= $isPaid ? 'completed' : ($isCashPending ? 'active' : '') ?>" id="stepPayment">
            <div class="timeline-bullet">
              <i class="bi <?= $isPaid ? 'bi-check-lg' : 'bi-hourglass-split' ?>"></i>
            </div>
            <div class="timeline-content">
              <div class="timeline-title" id="timelinePaymentTitle"><?= $isPaid ? 'Payment Confirmed' : ($isCashPending ? 'Awaiting Counter Cash Payment' : 'Payment Verification') ?></div>
              <div class="timeline-desc" id="timelinePaymentDesc"><?= $isPaid ? 'Payment recorded & verified.' : 'Pay amount to the counter staff.' ?></div>
            </div>
          </li>

          <!-- Step 2: Queue -->
          <li class="timeline-step <?= $isPaid ? (in_array($job['status'], ['QUEUED', 'PAID']) ? 'active' : 'completed') : '' ?>" id="stepQueue">
            <div class="timeline-bullet">
              <i class="bi bi-cloud-check"></i>
            </div>
            <div class="timeline-content">
              <div class="timeline-title">Added to Print Queue</div>
              <div class="timeline-desc">Document spooled and waiting for counter printer</div>
            </div>
          </li>

          <!-- Step 3: Printing -->
          <li class="timeline-step <?= in_array($job['status'], ['DOWNLOADING', 'PRINTING'], true) ? 'active' : ($job['status'] === 'PRINTED' ? 'completed' : '') ?>" id="stepPrinting">
            <div class="timeline-bullet">
              <i class="bi bi-printer"></i>
            </div>
            <div class="timeline-content">
              <div class="timeline-title">Printing Document</div>
              <div class="timeline-desc">Counter printer is actively printing your pages</div>
            </div>
          </li>

          <!-- Step 4: Ready -->
          <li class="timeline-step <?= $job['status'] === 'PRINTED' ? 'completed' : '' ?>" id="stepReady">
            <div class="timeline-bullet">
              <i class="bi bi-box2-heart"></i>
            </div>
            <div class="timeline-content">
              <div class="timeline-title">Ready for Pickup</div>
              <div class="timeline-desc">Please collect your fresh prints from the counter</div>
            </div>
          </li>
        </ul>
      </div>

      <!-- Bottom Action -->
      <div class="d-grid gap-2">
        <a href="<?= APP_URL ?>/p/<?= e($job['shop_slug']) ?>" class="btn btn-outline-primary py-2 rounded-3">
          <i class="bi bi-plus-circle me-1"></i> Print Another Document
        </a>
      </div>

    </div>

    <!-- Shop Details Footer -->
    <div class="text-center text-muted small">
      <div class="fw-semibold text-dark"><?= e($job['shop_name']) ?></div>
      <div><i class="bi bi-geo-alt me-1"></i><?= e($job['shop_address'] ?? 'Shop Counter') ?> • <i class="bi bi-telephone me-1"></i><?= e($job['shop_phone']) ?></div>
      <div class="mt-1 text-secondary">&copy; <?= date('Y') ?> PrimePrint Cloud SaaS</div>
    </div>

  </div>

  <script>
    const orderToken = <?= json_encode($job['public_token']) ?>;
    const isInitiallyPaid = <?= json_encode($isPaid) ?>;
    let currentStatus = <?= json_encode($job['status']) ?>;
    let currentPaymentStatus = <?= json_encode($job['payment_status']) ?>;

    // Real-time polling
    async function checkOrderStatus() {
      try {
        const res = await fetch(`<?= APP_URL ?>/api/customer/order-status.php?token=${encodeURIComponent(orderToken)}`);
        if (!res.ok) return;
        const data = await res.json();
        if (!data.success) return;

        // If status changed, update UI
        if (data.status !== currentStatus || data.payment_status !== currentPaymentStatus) {
          currentStatus = data.status;
          currentPaymentStatus = data.payment_status;
          updateLiveUI(data);
        }
      } catch (e) {
        console.warn('Status poll failed:', e);
      }
    }

    function updateLiveUI(data) {
      const iconBox = document.getElementById('statusIconBox');
      const heading = document.getElementById('statusHeading');
      const subheading = document.getElementById('statusSubheading');
      const timelineSection = document.getElementById('timelineSection');

      if (data.is_rejected) {
        iconBox.className = 'd-inline-flex align-items-center justify-content-center rounded-circle mb-3 mx-auto shadow bg-danger text-white';
        iconBox.innerHTML = '<i class="bi bi-x-lg"></i>';
        heading.textContent = 'Print Request Rejected';
        subheading.innerHTML = 'Print request was not accepted by the shop.' + (data.rejection_reason ? `<br><span class="text-danger fw-semibold">Reason: ${data.rejection_reason}</span>` : '');
        if (timelineSection) timelineSection.style.display = 'none';
        return;
      }

      if (data.is_paid) {
        iconBox.className = 'd-inline-flex align-items-center justify-content-center rounded-circle mb-3 mx-auto shadow bg-success text-white';
        iconBox.innerHTML = '<i class="bi bi-check-lg"></i>';
        heading.textContent = 'Payment Accepted ✓';
        subheading.innerHTML = `Your document is processing at <strong>${data.shop_name}</strong>.`;

        // Update Timeline
        const stepPayment = document.getElementById('stepPayment');
        if (stepPayment) {
          stepPayment.className = 'timeline-step completed';
          document.getElementById('timelinePaymentTitle').textContent = 'Payment Confirmed';
          document.getElementById('timelinePaymentDesc').textContent = 'Payment recorded & verified.';
        }

        const stepQueue = document.getElementById('stepQueue');
        if (stepQueue) {
          if (['QUEUED', 'PAID'].includes(data.status)) {
            stepQueue.className = 'timeline-step active';
          } else {
            stepQueue.className = 'timeline-step completed';
          }
        }

        const stepPrinting = document.getElementById('stepPrinting');
        if (stepPrinting) {
          if (['DOWNLOADING', 'PRINTING'].includes(data.status)) {
            stepPrinting.className = 'timeline-step active';
          } else if (data.status === 'PRINTED') {
            stepPrinting.className = 'timeline-step completed';
          }
        }

        const stepReady = document.getElementById('stepReady');
        if (stepReady && data.status === 'PRINTED') {
          stepReady.className = 'timeline-step completed';
          heading.textContent = 'Prints Ready for Pickup! 🎉';
        }
      }
    }

    // Poll every 3.5 seconds
    setInterval(checkOrderStatus, 3500);
  </script>
</body>
</html>
