<?php
/**
 * PrimePrint Shop - Printing Prices Management
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/csrf.php';
require_once __DIR__ . '/../config/helpers.php';

require_role('shop');

$db = getDBConnection();
$shopUser = current_user();
$shopId = verify_shop_access($shopUser['shop_id']);

$errors = [];

// Handle Save/Update Pricing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save_pricing') {
    require_csrf_token();

    $paperSize = $_POST['paper_size'] ?? 'A4';
    $colorMode = $_POST['color_mode'] ?? 'BW';
    $sideMode  = $_POST['side_mode'] ?? 'single';
    $price     = (float)($_POST['price_per_page'] ?? 0);
    $active    = isset($_POST['active']) ? 1 : 0;

    if ($price <= 0) {
        $errors[] = 'Price per page must be greater than zero.';
    } else {
        $stmt = $db->prepare("
            INSERT INTO pricing (shop_id, paper_size, color_mode, side_mode, price_per_page, active)
            VALUES (:shop_id, :paper_size, :color_mode, :side_mode, :price, :active)
            ON DUPLICATE KEY UPDATE price_per_page = :price_up, active = :active_up
        ");
        $stmt->execute([
            ':shop_id'    => $shopId,
            ':paper_size' => $paperSize,
            ':color_mode' => $colorMode,
            ':side_mode'  => $sideMode,
            ':price'      => $price,
            ':active'     => $active,
            ':price_up'   => $price,
            ':active_up'  => $active
        ]);

        flash_set('success', "Pricing for {$paperSize} {$colorMode} ({$sideMode}-sided) updated to ₹" . number_format($price, 2) . ".");
        header("Location: " . APP_URL . "/shop/pricing.php");
        exit;
    }
}

// Handle Delete Pricing
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_pricing') {
    require_csrf_token();
    $pricingId = (int)($_POST['pricing_id'] ?? 0);

    $stmt = $db->prepare("DELETE FROM pricing WHERE id = :id AND shop_id = :shop_id");
    $stmt->execute([':id' => $pricingId, ':shop_id' => $shopId]);
    flash_set('success', 'Pricing tier removed.');
    header("Location: " . APP_URL . "/shop/pricing.php");
    exit;
}

// Fetch all pricing rules for this shop
$stmt = $db->prepare("SELECT * FROM pricing WHERE shop_id = :shop_id ORDER BY paper_size, color_mode, side_mode");
$stmt->execute([':shop_id' => $shopId]);
$pricingTiers = $stmt->fetchAll();

$pageTitle = 'Printing Prices — ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
  <div>
    <h3 class="fw-bold text-dark mb-1">Printing Pricing Configuration</h3>
    <p class="text-muted small mb-0">Set your custom rate per page for each paper size, color mode, and side combination.</p>
  </div>
  <button class="btn btn-primary btn-sm d-flex align-items-center gap-2" data-bs-toggle="modal" data-bs-target="#addPriceModal">
    <i class="bi bi-plus-circle-fill"></i> Add / Update Price Tier
  </button>
</div>

<?php if (!empty($errors)): ?>
  <div class="alert alert-danger py-2">
    <ul class="mb-0 ps-3">
      <?php foreach ($errors as $err): ?>
        <li><?= e($err) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<!-- Pricing Matrix Card -->
<div class="card card-pp mb-4">
  <div class="card-pp-header">
    <h5 class="card-pp-title"><i class="bi bi-currency-rupee me-2 text-primary"></i>Active Shop Price Matrix</h5>
  </div>
  <div class="card-body p-0">
    <div class="table-responsive">
      <table class="table table-pp table-hover mb-0">
        <thead>
          <tr>
            <th>Paper Size</th>
            <th>Color Mode</th>
            <th>Side Mode</th>
            <th>Rate / Page</th>
            <th>Status</th>
            <th>Last Updated</th>
            <th class="text-end">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($pricingTiers)): ?>
            <tr>
              <td colspan="7" class="text-center py-5 text-muted">
                No pricing tiers defined. Click "Add / Update Price Tier" to set your rates.
              </td>
            </tr>
          <?php else: ?>
            <?php foreach ($pricingTiers as $pt): ?>
              <tr>
                <td><span class="badge bg-light text-dark border fs-6"><?= e($pt['paper_size']) ?></span></td>
                <td>
                  <span class="badge <?= $pt['color_mode'] === 'COLOR' ? 'bg-primary-subtle text-primary border border-primary-subtle' : 'bg-secondary-subtle text-secondary' ?>">
                    <?= e($pt['color_mode']) ?>
                  </span>
                </td>
                <td><?= ucfirst(e($pt['side_mode'])) ?> Sided</td>
                <td class="fw-bold text-dark fs-6"><?= format_currency($pt['price_per_page']) ?></td>
                <td>
                  <span class="badge-status <?= $pt['active'] ? 'active' : 'inactive' ?>">
                    <?= $pt['active'] ? 'Active' : 'Disabled' ?>
                  </span>
                </td>
                <td class="small text-muted"><?= format_date($pt['updated_at']) ?></td>
                <td class="text-end">
                  <form method="POST" action="<?= APP_URL ?>/shop/pricing.php" class="d-inline" onsubmit="return confirm('Delete this pricing tier?');">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete_pricing">
                    <input type="hidden" name="pricing_id" value="<?= $pt['id'] ?>">
                    <button type="submit" class="btn btn-outline-danger btn-sm" title="Delete Tier">
                      <i class="bi bi-trash"></i>
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

<!-- Modal: Add / Update Price Tier -->
<div class="modal fade" id="addPriceModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="POST" action="<?= APP_URL ?>/shop/pricing.php">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save_pricing">

        <div class="modal-header">
          <h5 class="modal-title fw-bold">Add or Update Pricing Tier</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          
          <div class="mb-3">
            <label class="form-label fw-semibold text-secondary">Paper Size</label>
            <select name="paper_size" class="form-select">
              <option value="A4">A4 (Standard Document)</option>
              <option value="A3">A3 (Large Format / Poster)</option>
              <option value="Legal">Legal</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold text-secondary">Color Mode</label>
            <select name="color_mode" class="form-select">
              <option value="BW">Black & White (Monochrome)</option>
              <option value="COLOR">Full Color</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold text-secondary">Sides</label>
            <select name="side_mode" class="form-select">
              <option value="single">Single Sided</option>
              <option value="double">Double Sided (Back-to-Back)</option>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold text-secondary">Price Per Page (₹) <span class="text-danger">*</span></label>
            <div class="input-group">
              <span class="input-group-text bg-light">₹</span>
              <input type="number" step="0.50" min="0.50" class="form-control" name="price_per_page" placeholder="2.00" required>
            </div>
          </div>

          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" name="active" value="1" id="activeSwitch" checked>
            <label class="form-check-label small fw-semibold text-secondary" for="activeSwitch">Active (Available for customer ordering)</label>
          </div>

        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary">Save Price Rule</button>
        </div>
      </form>
    </div>
  </div>
</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
