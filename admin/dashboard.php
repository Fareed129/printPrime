<?php
/**
 * PrimePrint Super Admin Dashboard
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';

require_role('admin');

$db = getDBConnection();

// Fetch Dashboard Metrics
// 1. Total Shops & Active Shops
$stmt = $db->query("SELECT COUNT(*) AS total, SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active FROM shops");
$shopCounts = $stmt->fetch();
$totalShops = (int)($shopCounts['total'] ?? 0);
$activeShops = (int)($shopCounts['active'] ?? 0);

// 2. Total Print Jobs & Today's Print Jobs
$stmt = $db->query("
    SELECT 
        COUNT(*) AS total,
        SUM(CASE WHEN DATE(created_at) = CURDATE() THEN 1 ELSE 0 END) AS today_jobs,
        SUM(CASE WHEN DATE(created_at) = CURDATE() AND status = 'PRINTED' THEN amount ELSE 0 END) AS today_revenue
    FROM print_jobs
");
$jobMetrics = $stmt->fetch();
$totalJobs = (int)($jobMetrics['total'] ?? 0);
$todayJobs = (int)($jobMetrics['today_jobs'] ?? 0);
$todayRevenue = (float)($jobMetrics['today_revenue'] ?? 0.00);

// 3. Online Agents & Online Printers
$stmt = $db->query("SELECT COUNT(*) AS total FROM print_agents WHERE status = 'online' AND last_seen >= NOW() - INTERVAL 90 SECOND");
$onlineAgents = (int)($stmt->fetch()['total'] ?? 0);


$stmt = $db->query("SELECT COUNT(*) AS total FROM printers WHERE status = 'online'");
$onlinePrinters = (int)($stmt->fetch()['total'] ?? 0);

// 4. Recent Print Jobs
$stmt = $db->query("
    SELECT j.*, s.name AS shop_name, s.slug AS shop_slug 
    FROM print_jobs j 
    INNER JOIN shops s ON j.shop_id = s.id 
    ORDER BY j.created_at DESC 
    LIMIT 8
");
$recentJobs = $stmt->fetchAll();

// 5. Recent Active Shops
$stmt = $db->query("
    SELECT s.*, 
           (SELECT COUNT(*) FROM print_jobs WHERE shop_id = s.id) AS total_jobs,
           (SELECT COUNT(*) FROM printers WHERE shop_id = s.id) AS total_printers
    FROM shops s 
    ORDER BY s.created_at DESC 
    LIMIT 5
");
$recentShops = $stmt->fetchAll();

$pageTitle = 'Super Admin Dashboard — ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex align-items-center justify-content-between mb-4">
  <div>
    <h3 class="fw-bold text-dark mb-1">Platform Dashboard</h3>
    <p class="text-muted small mb-0">Overview of shops, print operations, and live hardware status across PrimePrint.</p>
  </div>
  <a href="<?= APP_URL ?>/admin/shop-add.php" class="btn btn-primary btn-sm d-flex align-items-center gap-2">
    <i class="bi bi-plus-circle-fill"></i> Add New Shop
  </a>
</div>

<!-- KPI Metrics Grid -->
<div class="row g-3 mb-4">
  <div class="col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-shop"></i></div>
      <div>
        <div class="stat-value"><?= $totalShops ?></div>
        <div class="stat-label">Total Shops</div>
      </div>
    </div>
  </div>

  <div class="col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon green"><i class="bi bi-check-circle-fill"></i></div>
      <div>
        <div class="stat-value"><?= $activeShops ?></div>
        <div class="stat-label">Active Shops</div>
      </div>
    </div>
  </div>

  <div class="col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon purple"><i class="bi bi-file-earmark-text-fill"></i></div>
      <div>
        <div class="stat-value"><?= $totalJobs ?></div>
        <div class="stat-label">Total Jobs</div>
      </div>
    </div>
  </div>

  <div class="col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon amber"><i class="bi bi-calendar-event"></i></div>
      <div>
        <div class="stat-value"><?= $todayJobs ?></div>
        <div class="stat-label">Today's Jobs</div>
      </div>
    </div>
  </div>

  <div class="col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon cyan"><i class="bi bi-currency-rupee"></i></div>
      <div>
        <div class="stat-value"><?= format_currency($todayRevenue) ?></div>
        <div class="stat-label">Today's Revenue</div>
      </div>
    </div>
  </div>

  <div class="col-md-4 col-xl-2">
    <div class="stat-card">
      <div class="stat-icon green"><i class="bi bi-hdd-network-fill"></i></div>
      <div>
        <div class="stat-value"><?= $onlinePrinters ?> / <?= $onlineAgents ?></div>
        <div class="stat-label">Printers / Agents</div>
      </div>
    </div>
  </div>
</div>

<!-- Main Dashboard Content -->
<div class="row g-4">
  
  <!-- Recent Print Jobs -->
  <div class="col-lg-8">
    <div class="card card-pp">
      <div class="card-pp-header">
        <h5 class="card-pp-title"><i class="bi bi-clock-history me-2 text-primary"></i>Recent Platform Print Jobs</h5>
        <a href="<?= APP_URL ?>/admin/print-jobs.php" class="btn btn-link btn-sm text-decoration-none">View All</a>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-pp table-hover mb-0">
            <thead>
              <tr>
                <th>Job ID</th>
                <th>Shop</th>
                <th>File Name</th>
                <th>Specs</th>
                <th>Amount</th>
                <th>Status</th>
                <th>Time</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($recentJobs)): ?>
                <tr>
                  <td colspan="7" class="text-center py-4 text-muted">No print jobs submitted yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($recentJobs as $job): ?>
                  <tr>
                    <td><span class="fw-mono fw-bold text-dark">#<?= $job['id'] ?></span></td>
                    <td>
                      <a href="<?= APP_URL ?>/admin/shop-view.php?id=<?= $job['shop_id'] ?>" class="fw-semibold text-decoration-none">
                        <?= e($job['shop_name']) ?>
                      </a>
                    </td>
                    <td class="text-truncate" style="max-width: 140px;" title="<?= e($job['file_name']) ?>">
                      <i class="bi <?= str_contains($job['file_type'], 'pdf') ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-image text-primary' ?> me-1"></i>
                      <?= e($job['file_name']) ?>
                    </td>
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

  <!-- Printing Shops Summary -->
  <div class="col-lg-4">
    <div class="card card-pp">
      <div class="card-pp-header">
        <h5 class="card-pp-title"><i class="bi bi-buildings me-2 text-primary"></i>Recent Shops</h5>
        <a href="<?= APP_URL ?>/admin/shops.php" class="btn btn-link btn-sm text-decoration-none">Manage</a>
      </div>
      <div class="card-body p-0">
        <div class="list-group list-group-flush">
          <?php if (empty($recentShops)): ?>
            <div class="p-3 text-center text-muted">No shops created yet.</div>
          <?php else: ?>
            <?php foreach ($recentShops as $shop): ?>
              <div class="list-group-item p-3 d-flex align-items-center justify-content-between">
                <div>
                  <div class="fw-bold text-dark">
                    <a href="<?= APP_URL ?>/admin/shop-view.php?id=<?= $shop['id'] ?>" class="text-dark text-decoration-none">
                      <?= e($shop['name']) ?>
                    </a>
                  </div>
                  <div class="small text-muted">
                    <span><?= e($shop['owner_name']) ?></span> • <span><?= $shop['total_jobs'] ?> jobs</span>
                  </div>
                </div>
                <div class="d-flex align-items-center gap-2">
                  <span class="badge-status <?= $shop['status'] ?>"><?= ucfirst($shop['status']) ?></span>
                  <a href="<?= APP_URL ?>/p/<?= e($shop['slug']) ?>" target="_blank" class="btn btn-outline-secondary btn-sm" title="Customer Upload Page">
                    <i class="bi bi-box-arrow-up-right"></i>
                  </a>
                </div>
              </div>
            <?php endforeach; ?>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
