<?php
/**
 * PrimePrint Shop - Invoices Record
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
    SELECT i.*, j.file_name, j.page_count, j.copies 
    FROM invoices i 
    INNER JOIN print_jobs j ON i.job_id = j.id 
    WHERE i.shop_id = :shop_id 
    ORDER BY i.created_at DESC 
    LIMIT 100
");
$stmt->execute([':shop_id' => $shopId]);
$invoices = $stmt->fetchAll();

$pageTitle = 'Invoices — ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
  <h3 class="fw-bold text-dark mb-1">Customer Print Invoices</h3>
  <p class="text-muted small mb-0">Generated invoice slips and digital billing history for your customer orders.</p>
</div>

<div class="card card-pp">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-pp table-hover mb-0">
        <thead>
          <tr>
            <th>Invoice #</th>
            <th>Job Reference</th>
            <th>Document Description</th>
            <th>Volume</th>
            <th>Amount Paid</th>
            <th>Generated Date</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($invoices)): ?>
            <tr>
              <td colspan="6" class="text-center py-5 text-muted">
                <i class="bi bi-receipt fs-1 d-block mb-2 text-secondary"></i>
                No invoices generated yet.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($invoices as $inv): ?>
              <tr>
                <td><span class="fw-mono fw-bold text-primary"><?= e($inv['invoice_number']) ?></span></td>
                <td><span class="fw-semibold">#<?= $inv['job_id'] ?></span></td>
                <td><?= e($inv['file_name']) ?></td>
                <td><?= $inv['page_count'] ?> pgs × <?= $inv['copies'] ?> copies</td>
                <td class="fw-bold text-dark"><?= format_currency($inv['amount']) ?></td>
                <td class="small text-muted"><?= format_date($inv['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
