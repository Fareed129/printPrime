<?php
/**
 * PrimePrint Admin - Detailed Shop View & Management
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';

require_role('admin');

$db = getDBConnection();
$shopId = (int)($_GET['id'] ?? 0);

if ($shopId <= 0) {
    flash_set('danger', 'Invalid shop ID.');
    header("Location: " . APP_URL . "/admin/shops.php");
    exit;
}

// 1. Fetch Shop Record
$stmt = $db->prepare("SELECT * FROM shops WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $shopId]);
$shop = $stmt->fetch();

if (!$shop) {
    flash_set('danger', 'Shop not found.');
    header("Location: " . APP_URL . "/admin/shops.php");
    exit;
}

// 2. Fetch Shop Primary User
$stmt = $db->prepare("SELECT * FROM users WHERE shop_id = :shop_id AND role = 'shop' LIMIT 1");
$stmt->execute([':shop_id' => $shopId]);
$shopUser = $stmt->fetch();

// 2b. Handle Admin Quick Password Reset for Shop
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'reset_password') {
    require_csrf_token();
    $newPass = trim($_POST['new_password'] ?? '');
    if (strlen($newPass) < 6) {
        flash_set('danger', 'Password must be at least 6 characters long.');
    } else {
        $hash = password_hash($newPass, PASSWORD_BCRYPT);
        $stmt = $db->prepare("UPDATE users SET password_hash = :hash WHERE shop_id = :shop_id AND role = 'shop'");
        $stmt->execute([':hash' => $hash, ':shop_id' => $shopId]);
        flash_set('success', "Login password for {$shop['name']} has been reset successfully.");
    }
    header("Location: " . APP_URL . "/admin/shop-view.php?id=" . $shopId);
    exit;
}


// 3. Fetch Metrics for this shop
$stmt = $db->prepare("
    SELECT 
        COUNT(*) AS total_jobs,
        SUM(CASE WHEN status = 'PRINTED' THEN 1 ELSE 0 END) AS completed_jobs,
        SUM(CASE WHEN status IN ('UPLOADED', 'PAYMENT_PENDING', 'QUEUED') THEN 1 ELSE 0 END) AS pending_jobs,
        SUM(CASE WHEN status = 'PRINTED' THEN amount ELSE 0 END) AS total_revenue
    FROM print_jobs 
    WHERE shop_id = :shop_id
");
$stmt->execute([':shop_id' => $shopId]);
$metrics = $stmt->fetch();

// 4. Fetch Printers
$stmt = $db->prepare("SELECT * FROM printers WHERE shop_id = :shop_id ORDER BY created_at DESC");
$stmt->execute([':shop_id' => $shopId]);
$printers = $stmt->fetchAll();

// 5. Fetch Print Agents
$stmt = $db->prepare("SELECT * FROM print_agents WHERE shop_id = :shop_id ORDER BY created_at DESC");
$stmt->execute([':shop_id' => $shopId]);
$agents = $stmt->fetchAll();

// 6. Fetch Pricing Rules
$stmt = $db->prepare("SELECT * FROM pricing WHERE shop_id = :shop_id ORDER BY paper_size, color_mode, side_mode");
$stmt->execute([':shop_id' => $shopId]);
$pricingRules = $stmt->fetchAll();

// 7. Fetch Recent Jobs
$stmt = $db->prepare("
    SELECT * FROM print_jobs 
    WHERE shop_id = :shop_id 
    ORDER BY created_at DESC 
    LIMIT 10
");
$stmt->execute([':shop_id' => $shopId]);
$shopJobs = $stmt->fetchAll();

$customerUrl = APP_URL . "/p/" . $shop['slug'];
$pageTitle = $shop['name'] . ' — Shop Details';
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <a href="<?= APP_URL ?>/admin/shops.php" class="text-decoration-none text-muted small">
      <i class="bi bi-arrow-left me-1"></i> Back to Shops List
    </a>
    <h3 class="fw-bold text-dark mt-1 mb-0 d-flex align-items-center gap-2">
      <?= e($shop['name']) ?>
      <span class="badge-status <?= $shop['status'] ?> fs-6"><?= ucfirst($shop['status']) ?></span>
    </h3>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= $customerUrl ?>" target="_blank" class="btn btn-outline-primary btn-sm d-flex align-items-center gap-1">
      <i class="bi bi-box-arrow-up-right"></i> Customer Portal
    </a>
    <a href="<?= APP_URL ?>/admin/shop-edit.php?id=<?= $shopId ?>" class="btn btn-primary btn-sm d-flex align-items-center gap-1">
      <i class="bi bi-pencil"></i> Edit Shop
    </a>
  </div>
</div>

<!-- Shop Metrics Grid -->
<div class="row g-3 mb-4">
  <div class="col-md-3">
    <div class="stat-card">
      <div class="stat-icon blue"><i class="bi bi-file-earmark-text"></i></div>
      <div>
        <div class="stat-value"><?= (int)$metrics['total_jobs'] ?></div>
        <div class="stat-label">Total Jobs</div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card">
      <div class="stat-icon green"><i class="bi bi-check2-circle"></i></div>
      <div>
        <div class="stat-value"><?= (int)$metrics['completed_jobs'] ?></div>
        <div class="stat-label">Completed</div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card">
      <div class="stat-icon amber"><i class="bi bi-hourglass-split"></i></div>
      <div>
        <div class="stat-value"><?= (int)$metrics['pending_jobs'] ?></div>
        <div class="stat-label">Pending</div>
      </div>
    </div>
  </div>
  <div class="col-md-3">
    <div class="stat-card">
      <div class="stat-icon cyan"><i class="bi bi-currency-rupee"></i></div>
      <div>
        <div class="stat-value"><?= format_currency($metrics['total_revenue']) ?></div>
        <div class="stat-label">Revenue Generated</div>
      </div>
    </div>
  </div>
</div>

<div class="row g-4 mb-4">
  
  <!-- Shop Profile & QR Card -->
  <div class="col-lg-4">
    <div class="card card-pp mb-4">
      <div class="card-pp-header">
        <h5 class="card-pp-title"><i class="bi bi-info-circle me-2 text-primary"></i>Shop Info</h5>
      </div>
      <div class="card-body p-3">
        <div class="mb-2">
          <span class="text-muted small d-block">Owner:</span>
          <span class="fw-semibold text-dark"><?= e($shop['owner_name']) ?></span>
        </div>
        <div class="mb-2">
          <span class="text-muted small d-block">Phone:</span>
          <span class="fw-semibold text-dark"><?= e($shop['phone']) ?></span>
        </div>
        <div class="mb-2">
          <span class="text-muted small d-block">Email:</span>
          <span class="fw-semibold text-dark"><?= e($shop['email']) ?></span>
        </div>
        <div class="mb-2">
          <span class="text-muted small d-block">Address:</span>
          <span class="small text-secondary"><?= nl2br(e($shop['address'] ?? 'Not specified')) ?></span>
        </div>
        <?php if ($shopUser): ?>
          <hr class="my-2">
          <div class="d-flex justify-content-between align-items-center">
            <div>
              <span class="text-muted small d-block">Shop Login Account:</span>
              <span class="badge bg-light text-dark border"><i class="bi bi-person me-1"></i><?= e($shopUser['email']) ?></span>
            </div>
            <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#resetShopPasswordModal">
              <i class="bi bi-key me-1"></i>Reset Password
            </button>
          </div>
        <?php endif; ?>

      </div>
    </div>

    <!-- QR Code Preview Card -->
    <div class="card card-pp text-center p-3">
      <div class="fw-bold text-dark mb-2">Customer QR Standee</div>
      <div id="adminShopQrCode" class="d-flex justify-content-center p-2 bg-white rounded border my-2"></div>
      <div class="small text-muted font-monospace text-truncate my-1"><?= $customerUrl ?></div>
      <button class="btn btn-outline-secondary btn-sm mt-2" onclick="copyToClipboard('<?= $customerUrl ?>', this)">
        <i class="bi bi-clipboard me-1"></i> Copy Customer URL
      </button>
    </div>
  </div>

  <!-- Hardware & Pricing -->
  <div class="col-lg-8">
    
    <!-- Printers & Agents Card -->
    <div class="card card-pp mb-4">
      <div class="card-pp-header">
        <h5 class="card-pp-title"><i class="bi bi-printer me-2 text-primary"></i>Hardware & Print Agents</h5>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-pp mb-0">
            <thead>
              <tr>
                <th>Printer Name</th>
                <th>Identifier</th>
                <th>Status</th>
                <th>Last Seen</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($printers)): ?>
                <tr>
                  <td colspan="4" class="text-center py-3 text-muted">No physical printers synced yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($printers as $p): ?>
                  <tr>
                    <td class="fw-semibold text-dark"><i class="bi bi-printer-fill text-secondary me-2"></i><?= e($p['printer_name']) ?></td>
                    <td><code><?= e($p['printer_identifier'] ?? '-') ?></code></td>
                    <td><span class="badge-status <?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span></td>
                    <td class="small text-muted"><?= format_date($p['last_seen']) ?></td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- Active Pricing Rules Card -->
    <div class="card card-pp">
      <div class="card-pp-header">
        <h5 class="card-pp-title"><i class="bi bi-currency-rupee me-2 text-primary"></i>Configured Pricing Tiers</h5>
      </div>
      <div class="card-body p-0">
        <div class="table-responsive">
          <table class="table table-pp mb-0">
            <thead>
              <tr>
                <th>Paper Size</th>
                <th>Color Mode</th>
                <th>Side Mode</th>
                <th>Rate / Page</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($pricingRules)): ?>
                <tr>
                  <td colspan="5" class="text-center py-3 text-muted">No pricing configured.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($pricingRules as $pr): ?>
                  <tr>
                    <td><span class="badge bg-light text-dark border"><?= e($pr['paper_size']) ?></span></td>
                    <td><?= e($pr['color_mode']) ?></td>
                    <td><?= ucfirst(e($pr['side_mode'])) ?> Sided</td>
                    <td class="fw-bold text-dark"><?= format_currency($pr['price_per_page']) ?></td>
                    <td>
                      <span class="badge-status <?= $pr['active'] ? 'active' : 'inactive' ?>">
                        <?= $pr['active'] ? 'Active' : 'Disabled' ?>
                      </span>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div>

</div>

<!-- Recent Shop Print Jobs -->
<div class="card card-pp">
  <div class="card-pp-header">
    <h5 class="card-pp-title"><i class="bi bi-file-earmark-text-fill me-2 text-primary"></i>Recent Jobs for this Shop</h5>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-pp table-hover mb-0">
        <thead>
          <tr>
            <th>Job ID</th>
            <th>File Name</th>
            <th>Pages</th>
            <th>Copies</th>
            <th>Preferences</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Created Time</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($shopJobs)): ?>
            <tr>
              <td colspan="8" class="text-center py-4 text-muted">No print jobs submitted yet for this shop.</td>
            </tr>
          <?php else: ?>
            <?php foreach ($shopJobs as $j): ?>
              <tr>
                <td class="fw-mono fw-bold">#<?= $j['id'] ?></td>
                <td>
                  <i class="bi <?= str_contains($j['file_type'], 'pdf') ? 'bi-file-earmark-pdf text-danger' : 'bi-file-earmark-image text-primary' ?> me-1"></i>
                  <?= e($j['file_name']) ?>
                </td>
                <td><?= $j['page_count'] ?></td>
                <td><?= $j['copies'] ?></td>
                <td><span class="badge bg-light text-secondary border"><?= e($j['paper_size']) ?> • <?= e($j['color_mode']) ?> • <?= e($j['side_mode']) ?></span></td>
                <td class="fw-bold text-dark"><?= format_currency($j['amount']) ?></td>
                <td><span class="badge-status <?= $j['status'] ?>"><?= $j['status'] ?></span></td>
                <td class="small text-muted"><?= format_date($j['created_at']) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<script>
(function() {
  function renderAdminShopQr() {
    const el = document.getElementById('adminShopQrCode');
    if (typeof QRCode !== 'undefined' && el) {
      try {
        el.innerHTML = '';
        new QRCode(el, {
          text: "<?= $customerUrl ?>",
          width: 140,
          height: 140
        });
      } catch (e) {
        console.warn('QRCode error in admin shop view', e);
      }
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', renderAdminShopQr);
  } else {
    renderAdminShopQr();
  }
  window.addEventListener('load', renderAdminShopQr);
})();
</script>


<!-- Reset Shop Password Modal -->
<div class="modal fade" id="resetShopPasswordModal" tabindex="-1" aria-labelledby="resetShopPasswordModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header">
        <h5 class="modal-title fw-bold" id="resetShopPasswordModalLabel"><i class="bi bi-key text-primary me-2"></i>Reset Shop Password</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <form method="POST" action="<?= APP_URL ?>/admin/shop-view.php?id=<?= $shopId ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="reset_password">
        <div class="modal-body p-4">
          <p class="text-muted small mb-3">Set a new login password for <strong><?= e($shop['name']) ?></strong> (Account: <code><?= e($shopUser['email'] ?? $shop['email']) ?></code>).</p>
          <div class="mb-3">
            <label class="form-label fw-semibold text-secondary small">New Password</label>
            <input type="password" class="form-control" name="new_password" placeholder="Minimum 6 characters" required minlength="6">
          </div>
        </div>
        <div class="modal-footer bg-light py-2">
          <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm px-3">Update Password</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>

