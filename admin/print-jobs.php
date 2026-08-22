<?php
/**
 * PrimePrint Admin - All Platform Print Jobs
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';

require_role('admin');

$db = getDBConnection();

// Fetch shops for filter dropdown
$shopsList = $db->query("SELECT id, name FROM shops ORDER BY name ASC")->fetchAll();

$shopFilter = (int)($_GET['shop_id'] ?? 0);
$statusFilter = trim($_GET['status'] ?? '');
$search = trim($_GET['q'] ?? '');

$sql = "
    SELECT j.*, s.name AS shop_name, s.slug AS shop_slug, p.printer_name 
    FROM print_jobs j 
    INNER JOIN shops s ON j.shop_id = s.id 
    LEFT JOIN printers p ON j.printer_id = p.id 
    WHERE 1=1
";
$params = [];

if ($shopFilter > 0) {
    $sql .= " AND j.shop_id = :shop_id";
    $params[':shop_id'] = $shopFilter;
}

if (!empty($statusFilter)) {
    $sql .= " AND j.status = :status";
    $params[':status'] = $statusFilter;
}

if (!empty($search)) {
    $sql .= " AND (j.file_name LIKE :search OR j.id = :search_id OR s.name LIKE :search)";
    $params[':search'] = "%{$search}%";
    $params[':search_id'] = (int)$search;
}

$sql .= " ORDER BY j.created_at DESC LIMIT 100";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$jobs = $stmt->fetchAll();

$pageTitle = 'Print Jobs — ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h3 class="fw-bold text-dark mb-1">Print Jobs Monitor</h3>
    <p class="text-muted small mb-0">Track live document queues, hardware spooling statuses, and order lifecycle.</p>
  </div>
</div>

<!-- Filter Bar -->
<div class="card card-pp mb-4">
  <div class="card-body p-3">
    <form method="GET" action="<?= APP_URL ?>/admin/print-jobs.php" class="row g-2 align-items-center">
      <div class="col-md-4">
        <div class="input-group">
          <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
          <input type="text" name="q" class="form-control" placeholder="Search by Job ID, file name..." value="<?= e($search) ?>">
        </div>
      </div>
      <div class="col-md-3">
        <select name="shop_id" class="form-select">
          <option value="0">All Printing Shops</option>
          <?php foreach ($shopsList as $s): ?>
            <option value="<?= $s['id'] ?>" <?= ($shopFilter === (int)$s['id']) ? 'selected' : '' ?>>
              <?= e($s['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <select name="status" class="form-select">
          <option value="">All Statuses</option>
          <?php foreach ([
            'UPLOADED', 'PAYMENT_PENDING', 'PAID', 'QUEUED', 
            'DOWNLOADING', 'PRINTING', 'PRINTED', 'FAILED', 'CANCELLED'
          ] as $st): ?>
            <option value="<?= $st ?>" <?= ($statusFilter === $st) ? 'selected' : '' ?>><?= $st ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2 d-flex gap-2">
        <button type="submit" class="btn btn-primary flex-fill">Filter</button>
        <?php if ($shopFilter > 0 || !empty($statusFilter) || !empty($search)): ?>
          <a href="<?= APP_URL ?>/admin/print-jobs.php" class="btn btn-outline-secondary">Reset</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Jobs Table -->
<div class="card card-pp">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-pp table-hover mb-0">
        <thead>
          <tr>
            <th>Job ID</th>
            <th>Shop</th>
            <th>Document File</th>
            <th>Pages & Copies</th>
            <th>Preferences</th>
            <th>Amount</th>
            <th>Payment</th>
            <th>Job Status</th>
            <th>Created Time</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($jobs)): ?>
            <tr>
              <td colspan="9" class="text-center py-5 text-muted">
                <i class="bi bi-inbox fs-1 d-block mb-2 text-secondary"></i>
                No matching print jobs found.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($jobs as $j): ?>
              <tr>
                <td><span class="fw-mono fw-bold text-dark">#<?= $j['id'] ?></span></td>
                <td>
                  <a href="<?= APP_URL ?>/admin/shop-view.php?id=<?= $j['shop_id'] ?>" class="text-dark fw-semibold text-decoration-none">
                    <?= e($j['shop_name']) ?>
                  </a>
                </td>
                <td class="text-truncate" style="max-width: 180px;" title="<?= e($j['file_name']) ?>">
                  <i class="bi <?= str_contains($j['file_type'], 'pdf') ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-image text-primary' ?> me-1"></i>
                  <?= e($j['file_name']) ?>
                </td>
                <td>
                  <span class="fw-semibold"><?= $j['page_count'] ?> pgs</span> × <?= $j['copies'] ?> <?= $j['copies'] > 1 ? 'copies' : 'copy' ?>
                </td>
                <td>
                  <span class="badge bg-light text-secondary border">
                    <?= e($j['paper_size']) ?> • <?= e($j['color_mode']) ?> • <?= e($j['side_mode']) ?>
                  </span>
                </td>
                <td class="fw-bold text-dark"><?= format_currency($j['amount']) ?></td>
                <td>
                  <span class="badge <?= $j['payment_status'] === 'completed' ? 'bg-success-subtle text-success' : 'bg-warning-subtle text-warning border' ?>">
                    <?= ucfirst($j['payment_status']) ?>
                  </span>
                </td>
                <td>
                  <span class="badge-status <?= e($j['status']) ?>">
                    <?= e($j['status']) ?>
                  </span>
                </td>
                <td class="small text-muted"><?= format_date($j['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
