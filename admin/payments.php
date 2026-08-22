<?php
/**
 * PrimePrint Admin - Platform Payments
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';

require_role('admin');

$db = getDBConnection();

$stmt = $db->query("
    SELECT p.*, s.name AS shop_name, j.file_name, j.amount AS job_amount 
    FROM payments p 
    INNER JOIN shops s ON p.shop_id = s.id 
    INNER JOIN print_jobs j ON p.job_id = j.id 
    ORDER BY p.created_at DESC 
    LIMIT 100
");
$payments = $stmt->fetchAll();

// Total Lifetime Captured Revenue
$stmt = $db->query("SELECT SUM(amount) AS total FROM payments WHERE status = 'captured'");
$totalCaptured = (float)($stmt->fetch()['total'] ?? 0.00);

$pageTitle = 'Payments — ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h3 class="fw-bold text-dark mb-1">Payment Transactions</h3>
    <p class="text-muted small mb-0">Platform-wide payment transaction history and settlement records.</p>
  </div>
  <div class="card card-pp px-3 py-2 bg-light">
    <span class="text-muted small">Total Captured Volume:</span>
    <span class="fw-bold fs-5 text-success"><?= format_currency($totalCaptured) ?></span>
  </div>
</div>

<div class="card card-pp">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-pp table-hover mb-0">
        <thead>
          <tr>
            <th>Payment ID</th>
            <th>Shop</th>
            <th>Job ID & Document</th>
            <th>Razorpay Order ID</th>
            <th>Gateway Payment ID</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Date & Time</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($payments)): ?>
            <tr>
              <td colspan="8" class="text-center py-5 text-muted">
                <i class="bi bi-wallet2 fs-1 d-block mb-2 text-secondary"></i>
                No payment transactions recorded yet.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($payments as $pay): ?>
              <tr>
                <td><span class="fw-mono fw-bold text-dark">#PAY-<?= $pay['id'] ?></span></td>
                <td class="fw-semibold text-dark"><?= e($pay['shop_name']) ?></td>
                <td>
                  <span class="text-muted">#<?= $pay['job_id'] ?></span> — <?= e($pay['file_name']) ?>
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
