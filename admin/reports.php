<?php
/**
 * PrimePrint Admin - Reports & Analytics
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';

require_role('admin');

$db = getDBConnection();

// 1. Performance By Shop
$stmt = $db->query("
    SELECT 
        s.id, s.name, s.slug, s.owner_name, s.status,
        COUNT(j.id) AS total_jobs,
        SUM(CASE WHEN j.status = 'PRINTED' THEN 1 ELSE 0 END) AS completed_jobs,
        SUM(CASE WHEN j.status = 'FAILED' THEN 1 ELSE 0 END) AS failed_jobs,
        SUM(CASE WHEN j.status = 'PRINTED' THEN j.amount ELSE 0 END) AS revenue
    FROM shops s 
    LEFT JOIN print_jobs j ON s.id = j.shop_id 
    GROUP BY s.id 
    ORDER BY revenue DESC, total_jobs DESC
");
$shopReports = $stmt->fetchAll();

// 2. Jobs Breakdown by Status
$stmt = $db->query("
    SELECT status, COUNT(*) as count, SUM(amount) as total_amount 
    FROM print_jobs 
    GROUP BY status
");
$statusBreakdown = $stmt->fetchAll();

$pageTitle = 'Reports & Analytics — ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
  <h3 class="fw-bold text-dark mb-1">Platform Analytics & Reports</h3>
  <p class="text-muted small mb-0">Financial summaries, printing volume breakdown, and shop performance metrics.</p>
</div>

<!-- Status Breakdown Cards -->
<div class="row g-3 mb-4">
  <?php foreach ($statusBreakdown as $sb): ?>
    <div class="col-md-3">
      <div class="card card-pp p-3">
        <div class="d-flex align-items-center justify-content-between">
          <span class="badge-status <?= $sb['status'] ?>"><?= $sb['status'] ?></span>
          <span class="fw-bold fs-5 text-dark"><?= $sb['count'] ?></span>
        </div>
        <div class="small text-muted mt-2">Volume: <?= format_currency($sb['total_amount']) ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Shop Performance Table -->
<div class="card card-pp">
  <div class="card-pp-header">
    <h5 class="card-pp-title"><i class="bi bi-bar-chart-line-fill me-2 text-primary"></i>Shop Performance Summary</h5>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-pp table-hover mb-0">
        <thead>
          <tr>
            <th>Shop Name</th>
            <th>Owner</th>
            <th>Total Jobs</th>
            <th>Completed</th>
            <th>Failed</th>
            <th>Success Rate</th>
            <th>Total Revenue</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($shopReports)): ?>
            <tr>
              <td colspan="7" class="text-center py-4 text-muted">No report data available.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($shopReports as $sr): 
              $total = (int)$sr['total_jobs'];
              $comp = (int)$sr['completed_jobs'];
              $rate = ($total > 0) ? round(($comp / $total) * 100, 1) : 0;
            ?>
              <tr>
                <td class="fw-bold text-dark"><?= e($sr['name']) ?></td>
                <td><?= e($sr['owner_name']) ?></td>
                <td><?= $total ?></td>
                <td><span class="text-success fw-semibold"><?= $comp ?></span></td>
                <td><span class="text-danger"><?= (int)$sr['failed_jobs'] ?></span></td>
                <td>
                  <div class="d-flex align-items-center gap-2">
                    <div class="progress flex-grow-1" style="height: 6px;">
                      <div class="progress-bar bg-success" style="width: <?= $rate ?>%"></div>
                    </div>
                    <span class="small fw-semibold"><?= $rate ?>%</span>
                  </div>
                </td>
                <td class="fw-bold text-dark fs-6"><?= format_currency($sr['revenue']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
