<?php
/**
 * PrimePrint Shop Portal - License & Subscription Management
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/razorpay.php';

require_role('shop');

$user = current_user();
$shopId = (int)$user['shop_id'];
$db = getDBConnection();

// Fetch Shop Details
$stmt = $db->prepare("
    SELECT s.*, 
           TIMESTAMPDIFF(DAY, NOW(), s.subscription_expires_at) AS days_left
    FROM shops s 
    WHERE s.id = :id 
    LIMIT 1
");
$stmt->execute([':id' => $shopId]);
$shop = $stmt->fetch();

if (!$shop) {
    die('Shop not found.');
}

// Fetch Active Subscription Plans
$plans = $db->query("SELECT * FROM subscription_plans WHERE status = 'active' ORDER BY duration_months ASC")->fetchAll();

// Fetch Past Subscription Payments
$stmt = $db->prepare("
    SELECT sub.*, p.name AS plan_title
    FROM shop_subscriptions sub
    LEFT JOIN subscription_plans p ON sub.plan_id = p.id
    WHERE sub.shop_id = :shop_id
    ORDER BY sub.id DESC
");
$stmt->execute([':shop_id' => $shopId]);
$history = $stmt->fetchAll();

$daysLeft = (int)($shop['days_left'] ?? 0);
$isExpired = empty($shop['subscription_expires_at']) || strtotime($shop['subscription_expires_at']) < time();
$pageTitle = 'License & Subscription — ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<!-- Breadcrumbs -->
<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">


        <div>
          <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
              <li class="breadcrumb-item"><a href="<?= APP_URL ?>/shop/dashboard.php">Shop Portal</a></li>
              <li class="breadcrumb-item active" aria-current="page">License & Subscription</li>
            </ol>
          </nav>
          <h2 class="h3 fw-bold mb-0">Shop License & Plan Management</h2>
        </div>
      </div>

      <?php if (isset($_GET['renewed'])): ?>
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-3 p-3 mb-4 shadow-sm" role="alert">
          <i class="bi bi-patch-check-fill fs-2 text-success"></i>
          <div>
            <h5 class="alert-heading fw-bold mb-1">Subscription Renewed Successfully! 🎉</h5>
            <p class="mb-0 small">Your PrimePrint shop license has been extended. Your customer upload portal and desktop print agent are fully active.</p>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <!-- License Status Hero Card -->
      <div class="card border-0 shadow-sm rounded-3 mb-4 bg-white overflow-hidden">
        <div class="card-body p-4">
          <div class="row align-items-center g-4">
            <div class="col-lg-8">
              <div class="d-flex align-items-center gap-3 mb-3">
                <div class="rounded-circle p-3 bg-<?= $isExpired ? 'danger' : ($isExpiring ? 'warning' : 'success') ?>-subtle text-<?= $isExpired ? 'danger' : ($isExpiring ? 'warning' : 'success') ?>">
                  <i class="bi bi-award-fill fs-2"></i>
                </div>
                <div>
                  <div class="text-muted small text-uppercase fw-semibold">Current License Status</div>
                  <h3 class="fw-bold text-dark mb-0">
                    <?= e($shop['plan_name'] ?? '3-Month Quarterly Pro') ?>
                  </h3>
                </div>
              </div>

              <div class="row g-3 py-2">
                <div class="col-sm-4">
                  <div class="small text-muted">License State</div>
                  <?php if ($isExpired): ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1 fs-6">
                      <i class="bi bi-x-circle me-1"></i>Expired
                    </span>
                  <?php elseif ($isExpiring): ?>
                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1 fs-6">
                      <i class="bi bi-hourglass-split me-1"></i>Expires in <?= $daysLeft ?> Days
                    </span>
                  <?php else: ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1 fs-6">
                      <i class="bi bi-check-circle me-1"></i>Active (<?= $daysLeft ?> Days Left)
                    </span>
                  <?php endif; ?>
                </div>

                <div class="col-sm-4">
                  <div class="small text-muted">Valid Until</div>
                  <div class="fw-bold text-dark fs-6">
                    <?= !empty($shop['subscription_expires_at']) ? date('d M Y, h:i A', strtotime($shop['subscription_expires_at'])) : 'Not Configured' ?>
                  </div>
                </div>

                <div class="col-sm-4">
                  <div class="small text-muted">Setup Fee</div>
                  <div class="fw-bold text-dark fs-6">
                    <?= $shop['setup_fee_paid'] ? '<span class="text-success"><i class="bi bi-check-circle me-1"></i>Paid</span>' : '<span class="text-warning">Pending</span>' ?>
                  </div>
                </div>
              </div>

              <?php if ($isExpired): ?>
                <div class="alert alert-danger py-2 px-3 small mt-3 mb-0 d-flex align-items-center gap-2">
                  <i class="bi bi-exclamation-octagon-fill fs-5"></i>
                  <div><strong>Counter Suspended:</strong> Customer upload QR page is offline. Renew your 3-month license below to restore instant operations.</div>
                </div>
              <?php elseif ($isExpiring): ?>
                <div class="alert alert-warning py-2 px-3 small mt-3 mb-0 d-flex align-items-center gap-2">
                  <i class="bi bi-exclamation-triangle-fill fs-5"></i>
                  <div><strong>Expiring Soon:</strong> Your license expires in <?= $daysLeft ?> days. Renew now to prevent customer queue interruptions.</div>
                </div>
              <?php endif; ?>
            </div>

            <div class="col-lg-4 text-lg-end">
              <div class="p-3 bg-light rounded-3 border text-center text-lg-end">
                <span class="small text-muted d-block mb-1">Instant Self-Service</span>
                <a href="#renewalOptions" class="btn btn-primary btn-lg w-100 fw-bold shadow-sm d-flex align-items-center justify-content-center gap-2">
                  <i class="bi bi-lightning-charge-fill"></i>
                  <span>Renew License Now</span>
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Renewal Options (Razorpay 1-Click & WhatsApp Support) -->
      <div id="renewalOptions" class="mb-4">
        <h4 class="fw-bold text-dark mb-3"><i class="bi bi-credit-card-2-front-fill me-2 text-primary"></i>Choose Your Renewal Method</h4>

        <div class="row g-4">
          <!-- 1. Direct Razorpay Online Plans -->
          <?php foreach ($plans as $plan): ?>
            <div class="col-12 col-md-6 col-lg-4">
              <div class="card h-100 border-2 rounded-3 p-4 bg-white position-relative <?= $plan['is_default'] ? 'border-primary shadow-sm' : 'border-light' ?>">
                <?php if ($plan['is_default']): ?>
                  <span class="position-absolute top-0 start-50 translate-middle badge rounded-pill bg-primary px-3 py-1 fw-bold">
                    RECOMMENDED PLAN
                  </span>
                <?php endif; ?>

                <div class="d-flex justify-content-between align-items-center mb-2">
                  <h5 class="fw-bold text-dark mb-0"><?= e($plan['name']) ?></h5>
                  <span class="badge bg-primary-subtle text-primary"><?= $plan['duration_months'] ?> Months</span>
                </div>

                <div class="my-3">
                  <span class="fs-1 fw-bold text-dark"><?= format_currency($plan['price']) ?></span>
                  <span class="text-muted small">/ <?= $plan['duration_months'] ?> months</span>
                </div>

                <p class="small text-muted mb-4"><?= e($plan['description']) ?></p>

                <ul class="list-unstyled small mb-4">
                  <li class="mb-2"><i class="bi bi-check2 text-success me-2"></i>Unlimited Customer Walk-In QR Uploads</li>
                  <li class="mb-2"><i class="bi bi-check2 text-success me-2"></i>Desktop Agent Automatic Spooling</li>
                  <li class="mb-2"><i class="bi bi-check2 text-success me-2"></i>Customer Razorpay Payment Processing</li>
                  <li class="mb-2"><i class="bi bi-check2 text-success me-2"></i>Instant +<?= $plan['duration_months'] * 30 ?> Days License Extension</li>
                </ul>

                <button type="button" class="btn btn-primary w-100 fw-bold py-2 mt-auto btn-renew-plan shadow-sm" 
                        data-plan-id="<?= $plan['id'] ?>" data-plan-name="<?= e($plan['name']) ?>" data-price="<?= $plan['price'] ?>">
                  <i class="bi bi-lightning-charge-fill me-1"></i> Pay <?= format_currency($plan['price']) ?> & Renew
                </button>
              </div>
            </div>
          <?php endforeach; ?>

          <!-- 2. Direct Support & Offline GPay / UPI Card -->
          <div class="col-12 col-md-6 col-lg-4">
            <div class="card h-100 border rounded-3 p-4 bg-white shadow-sm d-flex flex-column">
              <div class="d-flex align-items-center gap-2 mb-2">
                <i class="bi bi-chat-dots-fill text-success fs-4"></i>
                <h5 class="fw-bold text-dark mb-0">Offline Transfer & Support</h5>
              </div>
              <p class="small text-muted mb-3">Prefer paying via direct UPI QR, GPay, PhonePe, or Bank Transfer?</p>

              <div class="bg-light p-3 rounded-3 border mb-3 small">
                <div><strong>Support Contact:</strong> +91 98765 43210</div>
                <div><strong>Email:</strong> support@primeprint.local</div>
                <div><strong>Hours:</strong> Mon – Sat, 9 AM – 9 PM</div>
              </div>

              <?php 
                $waMsg = urlencode("Hi PrimePrint Support, I want to renew the software license for my shop {$shop['name']} (Slug: {$shop['slug']}). Please share the UPI QR code.");
              ?>
              <div class="d-grid gap-2 mt-auto">
                <a href="https://wa.me/919876543210?text=<?= $waMsg ?>" target="_blank" class="btn btn-success fw-bold py-2 d-flex align-items-center justify-content-center gap-2">
                  <i class="bi bi-whatsapp"></i> Chat on WhatsApp
                </a>
                <a href="tel:+919876543210" class="btn btn-outline-secondary btn-sm py-2">
                  <i class="bi bi-telephone-fill me-1"></i> Call Support
                </a>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Payment History Ledger -->
      <div class="card border-0 shadow-sm rounded-3 bg-white">
        <div class="card-header bg-white border-bottom py-3 d-flex align-items-center justify-content-between">
          <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-receipt-cutoff me-2 text-primary"></i>Subscription Payment History</h5>
          <span class="small text-muted"><?= count($history) ?> Recorded Payments</span>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light small text-uppercase">
              <tr>
                <th class="ps-4">Receipt #</th>
                <th>Plan Name</th>
                <th>Amount</th>
                <th>Method</th>
                <th>Validity Period</th>
                <th>Reference</th>
                <th class="pe-4 text-end">Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($history)): ?>
                <tr>
                  <td colspan="7" class="text-center py-4 text-muted">No renewal records yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($history as $h): ?>
                  <tr>
                    <td class="ps-4 fw-bold">#SUB-<?= $h['id'] ?></td>
                    <td><span class="fw-semibold text-dark"><?= e($h['plan_title'] ?? '3-Month Quarterly Pro') ?></span></td>
                    <td><span class="fw-bold text-success"><?= format_currency($h['amount']) ?></span></td>
                    <td>
                      <?php if ($h['payment_method'] === 'razorpay'): ?>
                        <span class="badge bg-primary-subtle text-primary border"><i class="bi bi-lightning-charge-fill me-1"></i>Razorpay</span>
                      <?php elseif ($h['payment_method'] === 'upi'): ?>
                        <span class="badge bg-info-subtle text-info border"><i class="bi bi-qr-code me-1"></i>UPI</span>
                      <?php else: ?>
                        <span class="badge bg-light text-dark border">Manual / Cash</span>
                      <?php endif; ?>
                    </td>
                    <td class="small">
                      <?= date('d M Y', strtotime($h['starts_at'])) ?> → <strong><?= date('d M Y', strtotime($h['expires_at'])) ?></strong>
                    </td>
                    <td class="small text-muted"><?= e($h['razorpay_payment_id'] ?? $h['notes'] ?? '-') ?></td>
                    <td class="pe-4 text-end small text-muted"><?= date('d M Y', strtotime($h['created_at'])) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

<!-- Razorpay Checkout Integration Script -->
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
document.querySelectorAll('.btn-renew-plan').forEach(btn => {
  btn.addEventListener('click', async () => {
    const planId = btn.getAttribute('data-plan-id');
    const originalText = btn.innerHTML;
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Initializing Gateway...';

    try {
      // 1. Create Subscription Order on Server
      const res = await fetch('/api/payment/create-subscription-order.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ plan_id: planId })
      });
      const data = await res.json();

      if (!data.success) {
        alert('Failed to initialize renewal payment: ' + (data.error || 'Gateway error'));
        btn.disabled = false;
        btn.innerHTML = originalText;
        return;
      }

      // 2. Open Razorpay Checkout Modal
      const options = {
        key: data.key_id,
        amount: data.amount,
        currency: data.currency || 'INR',
        name: 'PrimePrint License Renewal',
        description: data.plan_name + ' for ' + data.shop_name,
        order_id: data.order_id,
        prefill: {
          name: data.shop_name,
          email: data.shop_email,
          contact: data.shop_phone
        },
        theme: { color: '#2563eb' },
        handler: async function (response) {
          btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span> Verifying & Extending License...';

          try {
            // 3. Verify Payment on Server & Auto-Extend
            const verifyRes = await fetch('/api/payment/verify-subscription.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: JSON.stringify({
                plan_id: planId,
                razorpay_order_id: response.razorpay_order_id,
                razorpay_payment_id: response.razorpay_payment_id,
                razorpay_signature: response.razorpay_signature
              })
            });
            const verifyData = await verifyRes.json();

            if (verifyData.success) {
              window.location.href = verifyData.redirect_url;
            } else {
              alert('Payment signature verification failed: ' + verifyData.error);
              btn.disabled = false;
              btn.innerHTML = originalText;
            }
          } catch (e) {
            alert('Network error confirming license. Please contact support.');
            btn.disabled = false;
            btn.innerHTML = originalText;
          }
        },
        modal: {
          ondismiss: function () {
            btn.disabled = false;
            btn.innerHTML = originalText;
          }
        }
      };

      const rzp = new Razorpay(options);
      rzp.open();

    } catch (err) {
      alert('Unable to connect to payment gateway.');
      btn.disabled = false;
      btn.innerHTML = originalText;
    }
  });
});
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
