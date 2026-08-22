<?php
/**
 * PrimePrint Shop - Payments Received
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';

require_role('shop');

$db = getDBConnection();
$shopUser = current_user();
$shopId = verify_shop_access($shopUser['shop_id']);

$stmt = $db->prepare("
    SELECT p.*, j.file_name, j.amount AS job_amount, j.public_token 
    FROM payments p 
    INNER JOIN print_jobs j ON p.job_id = j.id 
    WHERE p.shop_id = :shop_id 
    ORDER BY p.created_at DESC 
    LIMIT 100
");
$stmt->execute([':shop_id' => $shopId]);
$payments = $stmt->fetchAll();

// Lifetime shop revenue captured
$stmt = $db->prepare("SELECT SUM(amount) AS total FROM payments WHERE shop_id = :shop_id AND status = 'captured'");
$stmt->execute([':shop_id' => $shopId]);
$totalShopEarnings = (float)($stmt->fetch()['total'] ?? 0.00);

$pageTitle = 'Payments — ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h3 class="fw-bold text-dark mb-1">Customer Payments</h3>
    <p class="text-muted small mb-0">Record of customer online payments and transaction settlements for this shop.</p>
  </div>
  <div class="card card-pp px-3 py-2 bg-light">
    <span class="text-muted small">Total Settled Volume:</span>
    <span class="fw-bold fs-5 text-success"><?= format_currency($totalShopEarnings) ?></span>
  </div>
</div>

<div class="card card-pp">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-pp table-hover mb-0">
        <thead>
          <tr>
            <th>Payment ID</th>
            <th>Print Job & Document</th>
            <th>Razorpay Order ID</th>
            <th>Gateway Transaction ID</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Date & Time</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($payments)): ?>
            <tr>
              <td colspan="7" class="text-center py-5 text-muted">
                <i class="bi bi-wallet2 fs-1 d-block mb-2 text-secondary"></i>
                No payment transactions recorded yet.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($payments as $pay): ?>
              <tr>
                <td><span class="fw-mono fw-bold text-dark">#PAY-<?= $pay['id'] ?></span></td>
                <td>
                  <span class="fw-bold text-dark">#<?= $pay['job_id'] ?></span>
                  <?php if (!empty($pay['public_token'])): ?>
                    <div class="small text-primary font-monospace" style="font-size: 0.72rem;"><?= e($pay['public_token']) ?></div>
                  <?php endif; ?>
                  <div class="small text-muted text-truncate" style="max-width: 140px;"><?= e($pay['file_name']) ?></div>
                </td>
                <td><code><?= e($pay['razorpay_order_id']) ?></code></td>
                <td><code><?= e($pay['razorpay_payment_id'] ?? 'N/A') ?></code></td>
                <td class="fw-bold text-dark"><?= format_currency($pay['amount']) ?></td>
                <td>
                  <span class="badge-status <?= $pay['status'] === 'captured' ? 'active' : 'pending' ?>">
                    <?= ucfirst($pay['status']) ?>
                  </span>
                </td>
                <td class="small text-muted"><?= format_date($pay['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
