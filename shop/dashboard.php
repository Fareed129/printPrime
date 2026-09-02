<?php
/**
 * PrimePrint Shop Dashboard
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';

require_role('shop');

$db = getDBConnection();
$shopUser = current_user();
$shopId = verify_shop_access($shopUser['shop_id']);

// Fetch Shop Details
$stmt = $db->prepare("SELECT * FROM shops WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $shopId]);
$shop = $stmt->fetch();

// 1. Metrics for this shop
$stmt = $db->prepare("
    SELECT 
        COUNT(*) AS total_jobs,
        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_jobs,
        SUM(CASE WHEN status IN ('UPLOADED', 'PAYMENT_PENDING', 'QUEUED') THEN 1 ELSE 0 END) AS pending_jobs,
        SUM(CASE WHEN status = 'PRINTED' THEN 1 ELSE 0 END) AS completed_jobs,
        SUM(CASE WHEN status = 'FAILED' THEN 1 ELSE 0 END) AS failed_jobs,
        SUM(CASE WHEN DATE(created_at) = CURDATE() AND status = 'PRINTED' THEN amount ELSE 0 END) AS today_revenue,
        SUM(CASE WHEN status = 'PRINTED' THEN amount ELSE 0 END) AS total_revenue
    FROM print_jobs 
    WHERE shop_id = :shop_id
");
$stmt->execute([':shop_id' => $shopId]);
$metrics = $stmt->fetch();

// 2. Hardware Status (Printers & Agents)
$stmt = $db->prepare("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'online' THEN 1 ELSE 0 END) AS online FROM printers WHERE shop_id = :shop_id");
$stmt->execute([':shop_id' => $shopId]);
$printerMetrics = $stmt->fetch();
$onlinePrinters = (int)($printerMetrics['online'] ?? 0);
$totalPrinters = (int)($printerMetrics['total'] ?? 0);

$stmt = $db->prepare("SELECT * FROM print_agents WHERE shop_id = :shop_id ORDER BY last_seen DESC LIMIT 1");
$stmt->execute([':shop_id' => $shopId]);
$agent = $stmt->fetch();
$agentOnline = ($agent && $agent['status'] === 'online' && (time() - strtotime($agent['last_seen'])) < 120);

// 3. Recent Live Print Jobs for this Shop
$stmt = $db->prepare("
    SELECT * FROM print_jobs 
    WHERE shop_id = :shop_id 
    ORDER BY created_at DESC 
    LIMIT 8
");
$stmt->execute([':shop_id' => $shopId]);
$recentJobs = $stmt->fetchAll();

$customerUrl = APP_URL . "/p/" . $shop['slug'];
$pageTitle = 'Shop Dashboard — ' . $shop['name'];

// Check for pending cash approvals
$stmtPendingCash = $db->prepare("
    SELECT * FROM print_jobs 
    WHERE shop_id = :shop_id AND status = 'AWAITING_SHOP_APPROVAL'
    ORDER BY created_at ASC
");
$stmtPendingCash->execute([':shop_id' => $shopId]);
$pendingCashJobs = $stmtPendingCash->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h3 class="fw-bold text-dark mb-1"><?= e($shop['name']) ?></h3>
    <p class="text-muted small mb-0">Shop Operations & Real-Time Print Queue Management</p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= APP_URL ?>/shop/qr.php" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-2">
      <i class="bi bi-qr-code"></i> View Shop QR
    </a>
    <a href="<?= $customerUrl ?>" target="_blank" class="btn btn-primary btn-sm d-flex align-items-center gap-2">
      <i class="bi bi-box-arrow-up-right"></i> Customer Portal
    </a>
  </div>
</div>

<?php if (!empty($pendingCashJobs)): ?>
  <div class="alert alert-warning border-warning shadow-sm d-flex flex-wrap align-items-center justify-content-between p-3 mb-4 rounded-3 gap-3">
    <div class="d-flex align-items-center gap-3">
      <i class="bi bi-cash-stack fs-1 text-warning flex-shrink-0"></i>
      <div>
        <h6 class="fw-bold text-dark mb-1"><?= count($pendingCashJobs) ?> Cash Payment Request(s) Waiting at Counter</h6>
        <div class="text-muted small">Customers are waiting to pay cash. Click below to accept payments and begin printing.</div>
      </div>
    </div>
    <a href="<?= APP_URL ?>/shop/print-jobs.php?status=AWAITING_SHOP_APPROVAL" class="btn btn-warning text-dark fw-bold px-3 py-2 shadow-sm">
      <i class="bi bi-check-circle-fill me-1"></i> Review & Accept Cash (<?= count($pendingCashJobs) ?>)
    </a>
  </div>
<?php endif; ?>

<?php require_once __DIR__ . '/../includes/subscription-banner.php'; ?>


<!-- Shop KPI Grid -->

<div class="row g-3 mb-4">
  <div class="col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-calendar2-check"></i></div>
      <div>
        <div class="stat-value"><?= (int)$metrics['today_jobs'] ?></div>
        <div class="stat-label">Today's Jobs</div>
      </div>
    </div>
  </div>

  <div class="col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon amber"><i class="bi bi-hourglass-split"></i></div>
      <div>
        <div class="stat-value"><?= (int)$metrics['pending_jobs'] ?></div>
        <div class="stat-label">Pending Jobs</div>
      </div>
    </div>
  </div>

  <div class="col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon green"><i class="bi bi-check2-circle"></i></div>
      <div>
        <div class="stat-value"><?= (int)$metrics['completed_jobs'] ?></div>
        <div class="stat-label">Completed</div>
      </div>
    </div>
  </div>

  <div class="col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon red"><i class="bi bi-x-circle"></i></div>
      <div>
        <div class="stat-value"><?= (int)$metrics['failed_jobs'] ?></div>
        <div class="stat-label">Failed Jobs</div>
      </div>
    </div>
  </div>

  <div class="col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon cyan"><i class="bi bi-currency-rupee"></i></div>
      <div>
        <div class="stat-value"><?= format_currency($metrics['today_revenue']) ?></div>
        <div class="stat-label">Today's Revenue</div>
      </div>
    </div>
  </div>

  <div class="col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon <?= $onlinePrinters > 0 ? 'green' : 'amber' ?>"><i class="bi bi-printer-fill"></i></div>
      <div>
        <div class="stat-value"><?= $onlinePrinters ?> / <?= $totalPrinters ?></div>
        <div class="stat-label">Online Printers</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  
  <!-- Live Print Jobs Table -->
  <div class="col-lg-8">
    <div class="card card-pp">
      <div class="card-pp-header">
        <h5 class="card-pp-title"><i class="bi bi-clock-history me-2 text-primary"></i>Live Shop Print Queue</h5>
        <a href="<?= APP_URL ?>/shop/print-jobs.php" class="btn btn-link btn-sm text-decoration-none">View All Jobs</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-pp table-hover mb-0">
            <thead>
              <tr>
                <th>Job ID</th>
                <th>Document</th>
                <th>Pages</th>
                <th>Copies</th>
                <th>Specs</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Time</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentJobs)): ?>
                <tr>
                  <td colspan="8" class="text-center py-5 text-muted">
                    <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                    No print jobs received yet. Share your shop QR code with customers!
                  </td>
                </tr>
              <?php else: ?>
                <?php foreach ($recentJobs as $job): ?>
                  <tr>
                    <td><span class="fw-mono fw-bold text-dark">#<?= $job['id'] ?></span></td>
                    <td class="text-truncate" style="max-width: 140px;" title="<?= e($job['file_name']) ?>">
                      <i class="bi <?= str_contains($job['file_type'], 'pdf') ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-image text-primary' ?> me-1"></i>
                      <?= e($job['file_name']) ?>
                    </td>
                    <td><?= $job['page_count'] ?></td>
                    <td><?= $job['copies'] ?></td>
                    <td><span class="badge bg-light text-secondary border"><?= e($job['paper_size']) ?> • <?= e($job['color_mode']) ?></span></td>
                    <td class="fw-bold text-dark"><?= format_currency($job['amount']) ?></td>
                    <td>
                      <span class="badge-status <?= e($job['status']) ?>">
                        <?= e($job['status']) ?>
                      </span>
                    </td>
                    <td class="small text-muted"><?= format_date($job['created_at'], 'h:i A') ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>
  </div>

  <!-- Hardware & QR Mini Card -->
  <div class="col-lg-4">
    
    <!-- Print Agent Status Card -->
    <div class="card card-pp mb-4">
      <div class="card-pp-header">
        <h5 class="card-pp-title"><i class="bi bi-hdd-network me-2 text-primary"></i>Print Agent Status</h5>
      </div>
      <div class="card-body p-3">
        <?php if ($agent): ?>
          <div class="d-flex align-items-center justify-content-between mb-2">
            <span class="fw-semibold text-dark"><?= e($agent['agent_name']) ?></span>
            <span class="badge-status <?= $agentOnline ? 'online' : 'offline' ?>">
              <?= $agentOnline ? 'Online' : 'Offline' ?>
            </span>
          </div>
          <div class="small text-muted mb-1">Version: <?= e($agent['version']) ?></div>
          <div class="small text-muted">Last Heartbeat: <?= format_date($agent['last_seen']) ?></div>
        <?php else: ?>
          <div class="text-muted small">No Desktop Print Agent paired with this shop yet.</div>
          <div class="mt-2"><a href="<?= APP_URL ?>/shop/printers.php" class="btn btn-outline-primary btn-sm">Configure Agent</a></div>
        <?php endif; ?>
      </div>
    </div>

    <!-- Quick QR Card -->
    <div class="card card-pp text-center p-3">
      <h6 class="fw-bold text-dark mb-1">Customer QR Code</h6>
      <p class="small text-muted mb-2">Customers scan this QR to upload documents</p>
      <div id="shopDashboardQr" class="d-flex justify-content-center p-2 bg-white rounded border my-1" style="min-height: 140px; align-items: center;">
        <img src="https://api.qrserver.com/v1/create-qr-code/?size=130x130&data=<?= urlencode($customerUrl) ?>" alt="Customer QR" style="width: 130px; height: 130px; display: block;" crossorigin="anonymous">
      </div>
      <div class="small text-muted font-monospace text-truncate my-1"><?= $customerUrl ?></div>
      <div class="d-flex gap-2 justify-content-center mt-2">
        <button class="btn btn-outline-secondary btn-sm" onclick="copyToClipboard('<?= $customerUrl ?>', this)">
          <i class="bi bi-clipboard me-1"></i> Copy URL
        </button>
        <a href="<?= APP_URL ?>/shop/qr.php" class="btn btn-primary btn-sm">
          <i class="bi bi-printer me-1"></i> Print Standee
        </a>
      </div>
    </div>

  </div>

</div>

<script>
(function() {
  function renderDashQr() {
    const el = document.getElementById('shopDashboardQr');
    if (typeof QRCode !== 'undefined' && el) {
      try {
        el.innerHTML = '';
        new QRCode(el, {
          text: "<?= $customerUrl ?>",
          width: 130,
          height: 130
        });
      } catch (e) {
        console.warn('QRCode error in dashboard', e);
      }
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', renderDashQr);
  } else {
    renderDashQr();
  }
  window.addEventListener('load', renderDashQr);
})();
</script>


<?php require_once __DIR__ . '/../includes/footer.php'; ?>
