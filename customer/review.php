<?php
/**
 * PrimePrint Customer - Order Review & Checkout (V2)
 * Supports Online (Razorpay) and Cash Payment, Multi-file summary, and ID Card details.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/csrf.php';

$token = trim($_GET['token'] ?? '');

if (empty($token) || strlen($token) > 64) {
    http_response_code(404);
    die("Error 404: No valid order token specified. Please start your order from the shop page.");
}

try {
    $db = getDBConnection();
    $stmt = $db->prepare("
        SELECT j.*, s.name AS shop_name, s.slug AS shop_slug, s.phone AS shop_phone, s.address AS shop_address,
               s.razorpay_key_id, s.razorpay_key_secret
        FROM print_jobs j 
        INNER JOIN shops s ON j.shop_id = s.id 
        WHERE j.public_token = :token 
        LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
    $job = $stmt->fetch();

    if ($job) {
        $stmtFiles = $db->prepare("SELECT * FROM print_job_files WHERE job_id = :job_id ORDER BY sort_order ASC");
        $stmtFiles->execute([':job_id' => $job['id']]);
        $jobFiles = $stmtFiles->fetchAll();
    } else {
        $jobFiles = [];
    }
} catch (Throwable $e) {
    error_log("Review page query error: " . $e->getMessage());
    $job = null;
    $jobFiles = [];
}

if (!$job) {
    http_response_code(404);
    die("Error 404: Print order token not found or has expired.");
}

// Handle Direct Switch to Cash from Review Page
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'select_cash') {
    require_csrf_token();
    if ($job['payment_status'] === 'pending' || $job['payment_status'] === 'pending_cash') {
        $stmtCash = $db->prepare("
            UPDATE print_jobs 
            SET payment_method = 'CASH', payment_status = 'pending_cash', status = 'AWAITING_SHOP_APPROVAL'
            WHERE id = :id
        ");
        $stmtCash->execute([':id' => $job['id']]);
    }
    header("Location: " . APP_URL . "/customer/order-success.php?token=" . urlencode($token));
    exit;
}

// If this job is already paid or awaiting cash approval, redirect straight to order tracking
if ($job['payment_status'] === 'paid' || $job['status'] === 'AWAITING_SHOP_APPROVAL') {
    header("Location: " . APP_URL . "/customer/order-success.php?token=" . urlencode($token));
    exit;
}

$csrfToken = get_csrf_token();
$pageTitle = 'Review Order #' . $job['public_token'] . ' — ' . e($job['shop_name']);
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
  <!-- Custom Modern Design System -->
  <link rel="stylesheet" href="<?= asset_url('assets/css/style.css') ?>">
  <!-- Razorpay Official Checkout SDK -->
  <script src="https://checkout.razorpay.com/v1/checkout.js"></script>
</head>
<body class="customer-app-shell d-flex align-items-center justify-content-center" style="padding: 24px 12px 60px;">

  <div class="container" style="max-width: 520px;">
    
    <div class="receipt-card mb-4">
      
      <!-- Review Header -->
      <div class="text-center mb-3">
        <span class="badge bg-primary-subtle text-primary border border-primary-subtle rounded-pill px-3 py-1 fw-bold text-uppercase mb-2" style="font-size: 0.75rem;">
          <i class="bi bi-shop me-1"></i> <?= e($job['shop_name']) ?>
        </span>
        <h3 class="fw-bold text-dark mb-1 fs-4">Order Summary & Payment</h3>
        <p class="text-muted small mb-0">Review details and choose how you want to pay.</p>
      </div>

      <!-- Public Order Token Badge -->
      <div class="p-3 bg-light rounded-3 border text-center mb-3">
        <span class="text-muted small d-block text-uppercase fw-semibold" style="letter-spacing: 0.05em; font-size: 0.72rem;">Order Token</span>
        <span class="font-heading fw-bold fs-2 text-primary tracking-wide"><?= e($job['public_token']) ?></span>
      </div>

      <!-- Specifications Breakdown List -->
      <div class="list-group list-group-flush small mb-3">
        
        <!-- Document / Files Info -->
        <div class="list-group-item d-flex justify-content-between align-items-start px-0 py-2 border-bottom-0">
          <span class="text-muted"><i class="bi bi-file-earmark-text text-primary me-2"></i>Content:</span>
          <div class="text-end">
            <?php if (!empty($job['is_id_card'])): ?>
              <span class="fw-semibold text-dark">ID Card (Front + Back)</span>
              <div class="text-muted" style="font-size: 0.72rem;">Arranged on 1 A4 page</div>
            <?php elseif (count($jobFiles) > 1): ?>
              <span class="fw-semibold text-dark"><?= count($jobFiles) ?> Files</span>
              <div class="text-muted" style="font-size: 0.72rem;"><?= $job['page_count'] ?> total pages</div>
            <?php else: ?>
              <span class="fw-semibold text-dark text-truncate d-inline-block" style="max-width: 220px;" title="<?= e($job['file_name']) ?>">
                <?= e($job['file_name']) ?>
              </span>
            <?php endif; ?>
          </div>
        </div>

        <!-- Printable Pages Count -->
        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-bottom-0">
          <span class="text-muted"><i class="bi bi-file-earmark-break text-primary me-2"></i>Total Pages:</span>
          <span class="fw-bold text-dark badge bg-light text-secondary border px-2 py-1">
            <?= $job['page_count'] ?> <?= $job['page_count'] > 1 ? 'pages' : 'page' ?>
          </span>
        </div>

        <!-- Paper Size (Strictly A4) -->
        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-bottom-0">
          <span class="text-muted"><i class="bi bi-aspect-ratio text-primary me-2"></i>Paper Size:</span>
          <span class="fw-semibold text-dark">A4</span>
        </div>

        <!-- Color Mode -->
        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-bottom-0">
          <span class="text-muted"><i class="bi bi-palette text-primary me-2"></i>Color Mode:</span>
          <span class="fw-semibold text-dark"><?= $job['color_mode'] === 'COLOR' ? 'Full Color' : 'Black & White' ?></span>
        </div>

        <!-- Copies -->
        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-bottom-0">
          <span class="text-muted"><i class="bi bi-copy text-primary me-2"></i>Copies:</span>
          <span class="fw-semibold text-dark"><?= $job['copies'] ?> <?= $job['copies'] > 1 ? 'copies' : 'copy' ?></span>
        </div>

      </div>

      <!-- Child Files List (if multiple) -->
      <?php if (count($jobFiles) > 1): ?>
        <div class="card card-body bg-light border-0 p-2 mb-3">
          <div class="fw-bold text-secondary text-uppercase mb-1" style="font-size: 0.7rem; letter-spacing: 0.05em;">Included Files:</div>
          <?php foreach ($jobFiles as $jf): ?>
            <div class="d-flex justify-content-between align-items-center py-1 border-bottom" style="font-size: 0.78rem;">
              <span class="text-truncate me-2" style="max-width: 260px;"><?= e($jf['original_name']) ?></span>
              <span class="text-muted flex-shrink-0"><?= $jf['page_count'] ?> pg</span>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <!-- Perforated Digital Receipt Cut -->
      <div class="receipt-perforated-line"></div>

      <!-- Total Amount Display -->
      <div class="d-flex justify-content-between align-items-center mb-4 pt-1">
        <div>
          <span class="fw-bold text-dark fs-5 d-block">Grand Total</span>
          <span class="small text-muted">(<?= $job['page_count'] ?> pgs × <?= $job['copies'] ?> <?= $job['copies'] > 1 ? 'copies' : 'copy' ?>)</span>
        </div>
        <span class="fw-bold fs-2 text-success font-heading"><?= format_currency($job['amount']) ?></span>
      </div>

      <!-- Alert Box -->
      <div id="paymentAlertBox" class="alert alert-warning py-2 px-3 small d-flex align-items-center gap-2 mb-4 rounded-3">
        <i class="bi bi-hourglass-split fs-5 text-warning flex-shrink-0"></i>
        <div id="paymentAlertText">
          <strong>Choose Payment Method</strong><br>
          Pay securely online with UPI / cards, or choose cash at the counter.
        </div>
      </div>

      <!-- Payment Action Buttons -->
      <div class="d-grid gap-2 mb-3">
        <!-- Online Payment Button -->
        <button type="button" id="btnPayNow" class="btn btn-primary btn-lg fw-bold py-3 shadow rounded-3 fs-6">
          <i class="bi bi-shield-lock-fill me-2"></i> Pay <?= format_currency($job['amount']) ?> Online
        </button>

        <!-- Cash Payment Form Button -->
        <form method="POST" action="">
          <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
          <input type="hidden" name="action" value="select_cash">
          <button type="submit" class="btn btn-outline-warning w-100 text-dark fw-bold py-2 rounded-3">
            <i class="bi bi-cash-stack text-warning me-2"></i> Request Print & Pay at Counter
          </button>
        </form>

        <a href="<?= APP_URL ?>/p/<?= e($job['shop_slug']) ?>" class="btn btn-link text-muted btn-sm text-decoration-none">
          <i class="bi bi-arrow-left me-1"></i> Change Options / Re-upload
        </a>
      </div>

      <div class="d-flex align-items-center justify-content-center gap-3 mt-3 text-muted small border-top pt-3">
        <span><i class="bi bi-shield-check text-success me-1"></i>256-Bit SSL</span>
        <span>•</span>
        <span><i class="bi bi-lightning-charge-fill text-warning me-1"></i>Instant Print</span>
        <span>•</span>
        <span><i class="bi bi-qr-code text-primary me-1"></i>UPI Enabled</span>
      </div>

    </div>

    <!-- Shop Footer -->
    <div class="text-center text-muted small">
      <div class="fw-semibold text-dark"><?= e($job['shop_name']) ?></div>
      <div><i class="bi bi-geo-alt me-1"></i><?= e($job['shop_address'] ?? 'Shop Counter') ?> • <i class="bi bi-telephone me-1"></i><?= e($job['shop_phone']) ?></div>
      <div class="mt-1 text-secondary">&copy; <?= date('Y') ?> PrimePrint Cloud SaaS</div>
    </div>

  </div>

  <script>
    const orderToken = <?= json_encode($job['public_token']) ?>;
    const btnPayNow = document.getElementById('btnPayNow');
    const alertBox = document.getElementById('paymentAlertBox');
    const alertText = document.getElementById('paymentAlertText');

    function setAlert(type, message) {
      alertBox.className = `alert alert-${type} py-2 px-3 small d-flex align-items-center gap-2 mb-4 rounded-3`;
      const iconClass = type === 'danger' ? 'bi-x-circle-fill text-danger' : (type === 'success' ? 'bi-check-circle-fill text-success' : 'bi-hourglass-split text-warning');
      alertBox.querySelector('i').className = `bi ${iconClass} fs-5 flex-shrink-0`;
      alertText.innerHTML = message;
    }

    btnPayNow.addEventListener('click', async () => {
      btnPayNow.disabled = true;
      const originalBtnHtml = btnPayNow.innerHTML;
      btnPayNow.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Initializing Gateway...';

      try {
        const res = await fetch('<?= APP_URL ?>/api/payment/create-order.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ order_token: orderToken })
        });

        const data = await res.json();
        if (!data.success) {
          throw new Error(data.error || 'Failed to initialize payment gateway.');
        }

        const options = {
          key: data.key_id,
          amount: data.amount,
          currency: 'INR',
          name: data.shop_name,
          description: `Print Order #${orderToken}`,
          order_id: data.order_id,
          handler: async function (response) {
            btnPayNow.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Verifying Payment...';
            setAlert('info', '<strong>Payment received!</strong> Verifying with server...');
            
            const verifyRes = await fetch('<?= APP_URL ?>/api/payment/verify.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                order_token: orderToken,
                razorpay_order_id: response.razorpay_order_id,
                razorpay_payment_id: response.razorpay_payment_id,
                razorpay_signature: response.razorpay_signature
              })
            });

            const verifyData = await verifyRes.json();
            if (verifyData.success) {
              window.location.href = '<?= APP_URL ?>/customer/order-success.php?token=' + encodeURIComponent(orderToken);
            } else {
              setAlert('danger', '<strong>Verification error:</strong> ' + (verifyData.error || 'Payment could not be verified.'));
              btnPayNow.disabled = false;
              btnPayNow.innerHTML = originalBtnHtml;
            }
          },
          modal: {
            ondismiss: function () {
              btnPayNow.disabled = false;
              btnPayNow.innerHTML = originalBtnHtml;
            }
          },
          theme: { color: '#2563eb' }
        };

        const rzp = new Razorpay(options);
        rzp.open();

      } catch (err) {
        setAlert('danger', err.message || 'Payment service unavailable.');
        btnPayNow.disabled = false;
        btnPayNow.innerHTML = originalBtnHtml;
      }
    });
  </script>
</body>
</html>
