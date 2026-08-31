<?php
/**
 * PrimePrint Admin - Shop Management List
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/helpers.php';

require_role('admin');

$db = getDBConnection();

// Handle Status Toggle Action (Activate / Deactivate)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'toggle_status') {
    require_csrf_token();
    $shopId = (int)($_POST['shop_id'] ?? 0);
    $newStatus = ($_POST['current_status'] === 'active') ? 'inactive' : 'active';

    if ($shopId > 0) {
        $stmt = $db->prepare("UPDATE shops SET status = :status WHERE id = :id");
        $stmt->execute([':status' => $newStatus, ':id' => $shopId]);
        flash_set('success', "Shop status successfully updated to " . ucfirst($newStatus) . ".");
    }
    header("Location: " . APP_URL . "/admin/shops.php");
    exit;
}

// Search and Filter Params
$search = trim($_GET['q'] ?? '');
$statusFilter = trim($_GET['status'] ?? '');

$sql = "
    SELECT s.*, 
           COUNT(DISTINCT p.id) AS total_printers,
           COUNT(DISTINCT CASE WHEN a.status = 'online' AND a.last_seen >= NOW() - INTERVAL 90 SECOND THEN a.id END) AS online_agents,
           COUNT(DISTINCT j.id) AS total_jobs,
           TIMESTAMPDIFF(DAY, NOW(), s.subscription_expires_at) AS days_left
    FROM shops s

    LEFT JOIN printers p ON s.id = p.shop_id
    LEFT JOIN print_agents a ON s.id = a.shop_id
    LEFT JOIN print_jobs j ON s.id = j.shop_id
    WHERE 1=1
";

$params = [];

if (!empty($search)) {
    $sql .= " AND (s.name LIKE :search OR s.owner_name LIKE :search OR s.phone LIKE :search OR s.email LIKE :search OR s.slug LIKE :search)";
    $params[':search'] = "%{$search}%";
}

if (!empty($statusFilter) && in_array($statusFilter, ['active', 'inactive'])) {
    $sql .= " AND s.status = :status";
    $params[':status'] = $statusFilter;
}

$sql .= " GROUP BY s.id ORDER BY s.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$shops = $stmt->fetchAll();


$pageTitle = 'Shops Management — ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h3 class="fw-bold text-dark mb-1">Printing Shops</h3>
    <p class="text-muted small mb-0">Manage registered printing partners, hardware, and customer portals.</p>
  </div>
  <a href="<?= APP_URL ?>/admin/shop-add.php" class="btn btn-primary d-flex align-items-center gap-2">
    <i class="bi bi-plus-circle-fill"></i> Add New Shop
  </a>
</div>

<!-- Filters Card -->
<div class="card card-pp mb-4">
  <div class="card-body p-3">
    <form method="GET" action="<?= APP_URL ?>/admin/shops.php" class="row g-2 align-items-center">
      <div class="col-md-6">
        <div class="input-group">
          <span class="input-group-text bg-light"><i class="bi bi-search"></i></span>
          <input type="text" name="q" class="form-control" placeholder="Search by shop name, owner, phone, email..." value="<?= e($search) ?>">
        </div>
      </div>
      <div class="col-md-3">
        <select name="status" class="form-select">
          <option value="">All Statuses</option>
          <option value="active" <?= ($statusFilter === 'active') ? 'selected' : '' ?>>Active</option>
          <option value="inactive" <?= ($statusFilter === 'inactive') ? 'selected' : '' ?>>Inactive</option>
        </select>
      </div>
      <div class="col-md-3 d-flex gap-2">
        <button type="submit" class="btn btn-primary flex-fill">Filter</button>
        <?php if (!empty($search) || !empty($statusFilter)): ?>
          <a href="<?= APP_URL ?>/admin/shops.php" class="btn btn-outline-secondary">Reset</a>
        <?php endif; ?>
      </div>
    </form>
  </div>
</div>

<!-- Shops Table Card -->
<div class="card card-pp">
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-pp table-hover mb-0">
        <thead>
          <tr>
            <th>ID</th>
            <th>Shop Details</th>
            <th>Owner & Contact</th>
            <th>License / Expiry</th>
            <th>Hardware</th>
            <th>Jobs</th>
            <th>Status</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($shops)): ?>
            <tr>
              <td colspan="8" class="text-center py-5 text-muted">
                <i class="bi bi-shop fs-1 d-block mb-2 text-secondary"></i>
                No printing shops found. <a href="<?= APP_URL ?>/admin/shop-add.php">Add the first shop</a>
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($shops as $s): 
              $days = (int)($s['days_left'] ?? 0);
              $isExpired = empty($s['subscription_expires_at']) || strtotime($s['subscription_expires_at']) < time();
              $isExpiring = !$isExpired && $days <= 7;
            ?>
              <tr>
                <td><span class="text-muted fw-bold">#<?= $s['id'] ?></span></td>
                <td>
                  <div class="fw-bold text-dark">
                    <a href="<?= APP_URL ?>/admin/shop-view.php?id=<?= $s['id'] ?>" class="text-decoration-none text-dark">
                      <?= e($s['name']) ?>
                    </a>
                  </div>
                  <div class="small text-muted font-monospace">/p/<?= e($s['slug']) ?></div>
                </td>
                <td>
                  <div class="fw-semibold text-dark"><?= e($s['owner_name']) ?></div>
                  <div class="small text-muted"><i class="bi bi-telephone me-1"></i><?= e($s['phone']) ?></div>
                </td>
                <td>
                  <?php if ($isExpired): ?>
                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                      <i class="bi bi-x-circle me-1"></i>Expired
                    </span>
                  <?php elseif ($isExpiring): ?>
                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1">
                      <i class="bi bi-hourglass-split me-1"></i><?= $days ?>d left
                    </span>
                  <?php else: ?>
                    <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                      <i class="bi bi-check-circle me-1"></i><?= $days ?>d left
                    </span>
                  <?php endif; ?>
                  <div class="small text-muted mt-1"><?= !empty($s['subscription_expires_at']) ? date('d M Y', strtotime($s['subscription_expires_at'])) : 'No Date' ?></div>
                </td>
                <td>
                  <span class="badge bg-light text-dark border me-1"><i class="bi bi-printer me-1"></i><?= $s['total_printers'] ?></span>
                  <span class="badge <?= $s['online_agents'] > 0 ? 'bg-success-subtle text-success border border-success-subtle' : 'bg-secondary-subtle text-secondary' ?>">
                    <i class="bi bi-hdd-network me-1"></i><?= $s['online_agents'] ?>
                  </span>
                </td>
                <td class="fw-bold text-dark"><?= $s['total_jobs'] ?></td>
                <td>
                  <span class="badge-status <?= $s['status'] ?>"><?= ucfirst($s['status']) ?></span>
                </td>

                <td class="text-end">
                  <div class="btn-group btn-group-sm">
                    <a href="<?= APP_URL ?>/p/<?= e($s['slug']) ?>" target="_blank" class="btn btn-outline-secondary" title="View Customer Upload Page">
                      <i class="bi bi-qr-code"></i>
                    </a>
                    <a href="<?= APP_URL ?>/admin/shop-view.php?id=<?= $s['id'] ?>" class="btn btn-outline-primary" title="View Shop Profile & Metrics">
                      <i class="bi bi-eye"></i>
                    </a>
                    <a href="<?= APP_URL ?>/admin/shop-edit.php?id=<?= $s['id'] ?>" class="btn btn-outline-secondary" title="Edit Shop Details">
                      <i class="bi bi-pencil"></i>
                    </a>
                  </div>
                  
                  <form method="POST" action="<?= APP_URL ?>/admin/shops.php" class="d-inline-block ms-1">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="toggle_status">
                    <input type="hidden" name="shop_id" value="<?= $s['id'] ?>">
                    <input type="hidden" name="current_status" value="<?= $s['status'] ?>">
                    <button type="submit" class="btn btn-sm <?= $s['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?>" title="<?= $s['status'] === 'active' ? 'Deactivate Shop' : 'Activate Shop' ?>" onclick="return confirm('Are you sure you want to <?= $s['status'] === 'active' ? 'deactivate' : 'activate' ?> this shop?');">
                      <i class="bi <?= $s['status'] === 'active' ? 'bi-toggle-on' : 'bi-toggle-off' ?>"></i>
                    </button>
                  </form>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
