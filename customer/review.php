<?php
/**
 * PrimePrint Customer - Dedicated Order Review & Razorpay Checkout Integration
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/helpers.php';

$token = trim($_GET['token'] ?? '');

if (empty($token) || strlen($token) > 64) {
    http_response_code(404);
    die("Error 404: No valid order token specified. Please start your order from the shop page.");
}

try {
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
} catch (Exception $e) {
    error_log("Review page query error: " . $e->getMessage());
    $job = null;
}

if (!$job) {
    http_response_code(404);
    die("Error 404: Print order token not found or has expired.");
}

// If this job is already paid, redirect straight to order success
if ($job['payment_status'] === 'paid') {
    header("Location: " . APP_URL . "/customer/order-success.php?token=" . urlencode($token));
    exit;
}

$pageTitle = 'Review Order #' . $job['public_token'] . ' — ' . e($job['shop_name']);
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
        <h3 class="fw-bold text-dark mb-1 fs-4">Order Summary & Checkout</h3>
        <p class="text-muted small mb-0">Verify specifications and complete instant counter payment.</p>
      </div>

      <!-- Public Order Token Badge -->
      <div class="p-3 bg-light rounded-3 border text-center mb-3">
        <span class="text-muted small d-block text-uppercase fw-semibold" style="letter-spacing: 0.05em; font-size: 0.72rem;">Order Token</span>
        <span class="font-heading fw-bold fs-2 text-primary tracking-wide"><?= e($job['public_token']) ?></span>
      </div>

      <!-- Specifications Breakdown List -->
      <div class="list-group list-group-flush small mb-3">
        
        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-bottom-0">
          <span class="text-muted"><i class="bi bi-file-earmark-text text-primary me-2"></i>Document:</span>
          <span class="fw-semibold text-dark text-truncate" style="max-width: 240px;" title="<?= e($job['file_name']) ?>">
            <?= e($job['file_name']) ?>
          </span>
        </div>

        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-bottom-0">
          <span class="text-muted"><i class="bi bi-file-earmark-break text-primary me-2"></i>Pages:</span>
          <span class="fw-bold text-dark badge bg-light text-secondary border px-2 py-1">
            <?= $job['page_count'] ?> <?= $job['page_count'] > 1 ? 'pages' : 'page' ?>
          </span>
        </div>

        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-bottom-0">
          <span class="text-muted"><i class="bi bi-aspect-ratio text-primary me-2"></i>Paper Size:</span>

          <span class="fw-semibold text-dark"><?= e($job['paper_size']) ?></span>
        </div>

        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-bottom-0">
          <span class="text-muted"><i class="bi bi-palette text-primary me-2"></i>Color Mode:</span>
          <span class="fw-semibold text-dark"><?= $job['color_mode'] === 'COLOR' ? 'Full Color' : 'Black & White' ?></span>
        </div>

        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-bottom-0">
          <span class="text-muted"><i class="bi bi-layers text-primary me-2"></i>Sides:</span>
          <span class="fw-semibold text-dark"><?= ucfirst(e($job['side_mode'])) ?> Sided</span>
        </div>

        <div class="list-group-item d-flex justify-content-between align-items-center px-0 py-2 border-bottom-0">
          <span class="text-muted"><i class="bi bi-copy text-primary me-2"></i>Copies:</span>
          <span class="fw-semibold text-dark"><?= $job['copies'] ?> <?= $job['copies'] > 1 ? 'copies' : 'copy' ?></span>
        </div>

      </div>

      <!-- Perforated Digital Receipt Cut -->
      <div class="receipt-perforated-line"></div>

      <!-- Total Amount Display -->
      <div class="d-flex justify-content-between align-items-center mb-4 pt-1">
        <div>
          <span class="fw-bold text-dark fs-5 d-block">Grand Total</span>
          <span class="small text-muted">(<?= $job['page_count'] ?> pgs × <?= $job['copies'] ?> copies)</span>
        </div>
        <span class="fw-bold fs-2 text-success font-heading"><?= format_currency($job['amount']) ?></span>
      </div>

      <!-- Payment Status Alert -->
      <div id="paymentAlertBox" class="alert alert-warning py-2 px-3 small d-flex align-items-center gap-2 mb-4 rounded-3">
        <i class="bi bi-hourglass-split fs-5 text-warning flex-shrink-0"></i>
        <div id="paymentAlertText">
          <strong>Ready for Payment</strong><br>
          Tap below to pay securely via UPI, GPay, PhonePe, Cards, or NetBanking.
        </div>
      </div>

      <!-- Proceed to Payment Action Button -->
      <div class="d-grid gap-2">
        <button type="button" id="btnPayNow" class="btn btn-primary btn-lg fw-bold py-3 shadow rounded-3 fs-6">
          <i class="bi bi-shield-lock-fill me-2"></i> Pay <?= format_currency($job['amount']) ?> & Print
        </button>
        <a href="<?= APP_URL ?>/p/<?= e($job['shop_slug']) ?>" class="btn btn-outline-secondary btn-sm py-2 rounded-3">
          <i class="bi bi-arrow-left me-1"></i> Change Options / Re-upload
        </a>
      </div>

      <div class="d-flex align-items-center justify-content-center gap-3 mt-4 text-muted small border-top pt-3">
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

    function setAlert(type, message, isHtml = false) {
      alertBox.className = `alert alert-${type} py-2 px-3 small d-flex align-items-center gap-2 mb-4 rounded-3`;
      const iconClass = type === 'danger' ? 'bi-x-circle-fill text-danger' : (type === 'success' ? 'bi-check-circle-fill text-success' : 'bi-hourglass-split text-warning');
      alertBox.querySelector('i').className = `bi ${iconClass} fs-5 flex-shrink-0`;
      if (isHtml) {
        alertText.innerHTML = message;
      } else {
        alertText.textContent = message;
      }
    }

    let retryCount = 0;

    btnPayNow.addEventListener('click', async () => {
      btnPayNow.disabled = true;
      const originalBtnHtml = btnPayNow.innerHTML;
      btnPayNow.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Preparing Gateway...';

      try {
        // Step 1: Create or Reuse Razorpay Order on Server
        const orderResponse = await fetch('/api/payment/create-order.php', {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({
            token: orderToken,
            force_new: (retryCount > 0)
          })
        });

        const orderData = await orderResponse.json();

        if (orderData.is_paid && orderData.redirect_url) {
          window.location.href = orderData.redirect_url;
          return;
        }

        if (!orderData.success) {
          setAlert('danger', orderData.error || 'Failed to initialize payment gateway.');
          btnPayNow.disabled = false;
          btnPayNow.innerHTML = originalBtnHtml;
          return;
        }

        // Step 2: Open Razorpay Checkout Modal
        const options = {
          key: orderData.key_id,
          amount: orderData.amount,
          currency: orderData.currency || 'INR',
          name: orderData.shop_name || 'PrimePrint',
          description: 'Print Order #' + orderData.token,
          order_id: orderData.order_id,
          prefill: {
            name: 'Counter Customer',
            email: 'customer@primeprint.local',
            contact: '9876543210'
          },
          theme: { color: '#2563eb' },
          handler: async function (response) {
            btnPayNow.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Verifying Payment...';
            setAlert('info', 'Verifying transaction signature...');

            try {
              // Step 3: Verify Signature Server-Side
              const verifyResponse = await fetch('/api/payment/verify.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                  token: orderToken,
                  razorpay_payment_id: response.razorpay_payment_id,
                  razorpay_order_id: response.razorpay_order_id,
                  razorpay_signature: response.razorpay_signature
                })
              });

              const verifyData = await verifyResponse.json();
              if (verifyData.success) {
                setAlert('success', 'Payment verified successfully! Redirecting to live print tracker...');
                window.location.href = verifyData.redirect_url;
              } else {
                setAlert('danger', verifyData.error || 'Payment signature verification failed.');
                btnPayNow.disabled = false;
                btnPayNow.innerHTML = originalBtnHtml;
              }
            } catch (err) {
              setAlert('danger', 'Network error during verification. Please check with the counter.');
              btnPayNow.disabled = false;
              btnPayNow.innerHTML = originalBtnHtml;
            }
          },
          modal: {
            ondismiss: function () {
              retryCount++;
              setAlert('warning', '<strong>Payment Cancelled</strong><br>Checkout was closed. Tap Pay when ready to retry.', true);
              btnPayNow.disabled = false;
              btnPayNow.innerHTML = originalBtnHtml;
            }
          }
        };

        if (typeof Razorpay === 'undefined') {
          alert('Razorpay Checkout SDK is operating in mock test mode. Simulating client callback.');
          return;
        }

        const rzp = new Razorpay(options);
        rzp.on('payment.failed', function (resp) {
          retryCount++;
          setAlert('danger', `Payment failed: ${resp.error.description || 'Transaction declined'}`);
          btnPayNow.disabled = false;
          btnPayNow.innerHTML = originalBtnHtml;
        });

        rzp.open();

      } catch (err) {
        retryCount++;
        setAlert('danger', 'Unable to initiate payment gateway connection. Please try again.');
        btnPayNow.disabled = false;
        btnPayNow.innerHTML = originalBtnHtml;
      }
    });
  </script>

  <!-- Bootstrap 5.3 JS Bundle -->
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
