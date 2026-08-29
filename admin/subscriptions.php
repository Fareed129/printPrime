<?php
/**
 * Super Admin - Subscription Plans & Shop License Management
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/csrf.php';

require_role('admin');


$pageTitle = 'Subscriptions & Plans';
$db = getDBConnection();
$msgSuccess = '';
$msgError   = '';

// ----------------------------------------------------
// Handle Actions (Plan Updates & Manual License Extender)
// ----------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');
    $csrfToken = $_POST['csrf_token'] ?? '';

    if (!validate_csrf_token($csrfToken)) {
        $msgError = 'Security validation failed (invalid CSRF token). Please try again.';
    } else {
        // 1. Save or Update Subscription Plan
        if ($action === 'save_plan') {
            $planId = (int)($_POST['plan_id'] ?? 0);
            $name = trim($_POST['name'] ?? '');
            $duration = (int)($_POST['duration_months'] ?? 3);
            $setupFee = (float)($_POST['setup_fee'] ?? 0);
            $price = (float)($_POST['price'] ?? 0);
            $desc = trim($_POST['description'] ?? '');
            $status = in_array($_POST['status'] ?? '', ['active', 'inactive'], true) ? $_POST['status'] : 'active';
            $isDefault = !empty($_POST['is_default']) ? 1 : 0;

            if (empty($name) || $price < 0 || $duration < 1) {
                $msgError = 'Please provide a valid plan name, duration, and price.';
            } else {
                if ($isDefault) {
                    $db->exec("UPDATE subscription_plans SET is_default = 0");
                }
                if ($planId > 0) {
                    $stmt = $db->prepare("
                        UPDATE subscription_plans 
                        SET name = :name, duration_months = :dur, setup_fee = :setup, price = :price, description = :desc, is_default = :def, status = :status
                        WHERE id = :id
                    ");
                    $stmt->execute([
                        ':name'   => $name,
                        ':dur'    => $duration,
                        ':setup'  => $setupFee,
                        ':price'  => $price,
                        ':desc'   => $desc,
                        ':def'    => $isDefault,
                        ':status' => $status,
                        ':id'     => $planId
                    ]);
                    $msgSuccess = "Subscription plan '{$name}' updated successfully.";
                } else {
                    $stmt = $db->prepare("
                        INSERT INTO subscription_plans (name, duration_months, setup_fee, price, description, is_default, status)
                        VALUES (:name, :dur, :setup, :price, :desc, :def, :status)
                    ");
                    $stmt->execute([
                        ':name'   => $name,
                        ':dur'    => $duration,
                        ':setup'  => $setupFee,
                        ':price'  => $price,
                        ':desc'   => $desc,
                        ':def'    => $isDefault,
                        ':status' => $status
                    ]);
                    $msgSuccess = "New subscription plan '{$name}' created successfully.";
                }
            }
        }

        // 2. Manual License Grant / Offline Payment Extension
        elseif ($action === 'extend_license') {
            $shopId = (int)($_POST['shop_id'] ?? 0);
            $daysToAdd = (int)($_POST['days_to_add'] ?? 90);
            $customExpiry = trim($_POST['custom_expiry'] ?? '');
            $amountPaid = (float)($_POST['amount_paid'] ?? 0);
            $payMethod = trim($_POST['payment_method'] ?? 'manual_offline');
            $notes = trim($_POST['notes'] ?? 'Manual admin extension');

            $stmt = $db->prepare("SELECT * FROM shops WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $shopId]);
            $targetShop = $stmt->fetch();

            if (!$targetShop) {
                $msgError = 'Target shop not found.';
            } else {
                $currentExpiry = $targetShop['subscription_expires_at'];
                $baseTime = (!empty($currentExpiry) && strtotime($currentExpiry) > time()) 
                    ? strtotime($currentExpiry) 
                    : time();

                if (!empty($customExpiry) && strtotime($customExpiry) > time()) {
                    $newExpiry = date('Y-m-d 23:59:59', strtotime($customExpiry));
                } else {
                    $newExpiry = date('Y-m-d 23:59:59', strtotime("+{$daysToAdd} days", $baseTime));
                }

                // Update shop validity
                $stmt = $db->prepare("
                    UPDATE shops 
                    SET subscription_status = 'active',
                        subscription_expires_at = :expires_at,
                        setup_fee_paid = 1
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':expires_at' => $newExpiry,
                    ':id'         => $shopId
                ]);

                // Record in subscription payment ledger
                $stmt = $db->prepare("
                    INSERT INTO shop_subscriptions (shop_id, plan_id, amount, payment_method, starts_at, expires_at, notes)
                    VALUES (:shop_id, :plan_id, :amount, :method, :starts_at, :expires_at, :notes)
                ");
                $stmt->execute([
                    ':shop_id'   => $shopId,
                    ':plan_id'   => $targetShop['plan_id'] ?? 1,
                    ':amount'    => $amountPaid,
                    ':method'    => in_array($payMethod, ['cash', 'upi', 'manual_offline', 'razorpay'], true) ? $payMethod : 'manual_offline',
                    ':starts_at' => date('Y-m-d H:i:s', $baseTime),
                    ':expires_at'=> $newExpiry,
                    ':notes'     => $notes
                ]);

                $msgSuccess = "License for '{$targetShop['name']}' extended to " . date('d M Y', strtotime($newExpiry)) . " successfully.";
            }
        }
    }
}

// ----------------------------------------------------
// Load Analytics & Plans Data
// ----------------------------------------------------
// Plans
$plans = $db->query("SELECT * FROM subscription_plans ORDER BY duration_months ASC")->fetchAll();

// Financial KPIs
$totalRevenue = (float)$db->query("SELECT COALESCE(SUM(amount), 0) FROM shop_subscriptions")->fetchColumn();
$activeShopsCount = (int)$db->query("SELECT COUNT(*) FROM shops WHERE subscription_status = 'active' AND (subscription_expires_at IS NULL OR subscription_expires_at >= NOW())")->fetchColumn();
$expiringShopsCount = (int)$db->query("SELECT COUNT(*) FROM shops WHERE subscription_status = 'active' AND subscription_expires_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)")->fetchColumn();
$expiredShopsCount = (int)$db->query("SELECT COUNT(*) FROM shops WHERE subscription_status = 'expired' OR (subscription_expires_at IS NOT NULL AND subscription_expires_at < NOW())")->fetchColumn();

// Shops List with License details
$shops = $db->query("
    SELECT s.*, 
           TIMESTAMPDIFF(DAY, NOW(), s.subscription_expires_at) AS days_left
    FROM shops s 
    ORDER BY s.subscription_expires_at ASC
")->fetchAll();

// Recent Subscription Payment Ledger
$ledger = $db->query("
    SELECT sub.*, s.name AS shop_name, s.slug AS shop_slug, p.name AS plan_title
    FROM shop_subscriptions sub
    INNER JOIN shops s ON sub.shop_id = s.id
    LEFT JOIN subscription_plans p ON sub.plan_id = p.id
    ORDER BY sub.id DESC
    LIMIT 50
")->fetchAll();

require_once __DIR__ . '/../includes/header.php';
?>

<div class="app-wrapper">
  <?php require_once __DIR__ . '/../includes/admin-sidebar.php'; ?>

  <main class="app-main">
    <div class="container-fluid py-4">

      <!-- Breadcrumbs & Header -->
      <div class="d-flex flex-wrap align-items-center justify-content-between gap-3 mb-4">
        <div>
          <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-1">
              <li class="breadcrumb-item"><a href="<?= APP_URL ?>/admin/dashboard.php">Admin</a></li>
              <li class="breadcrumb-item active" aria-current="page">Subscriptions & Licensing</li>
            </ol>
          </nav>
          <h2 class="h3 fw-bold mb-0">SaaS Subscriptions & Shop Licenses</h2>
        </div>
        <div class="d-flex gap-2">
          <button type="button" class="btn btn-primary d-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalAddPlan">
            <i class="bi bi-plus-circle"></i>
            <span>Add Subscription Plan</span>
          </button>
          <button type="button" class="btn btn-success d-flex align-items-center gap-2 shadow-sm" data-bs-toggle="modal" data-bs-target="#modalExtendLicense">
            <i class="bi bi-clock-history"></i>
            <span>Manual License Grant</span>
          </button>
        </div>
      </div>

      <?php if (!empty($msgSuccess)): ?>
        <div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
          <i class="bi bi-check-circle-fill fs-5"></i>
          <div><?= e($msgSuccess) ?></div>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <?php if (!empty($msgError)): ?>
        <div class="alert alert-danger alert-dismissible fade show d-flex align-items-center gap-2" role="alert">
          <i class="bi bi-exclamation-triangle-fill fs-5"></i>
          <div><?= e($msgError) ?></div>
          <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
      <?php endif; ?>

      <!-- SaaS Financial KPIs -->
      <div class="row g-3 mb-4">
        <div class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm rounded-3 h-100 bg-white">
            <div class="card-body p-3 d-flex align-items-center gap-3">
              <div class="rounded-3 p-3 bg-primary-subtle text-primary">
                <i class="bi bi-currency-rupee fs-3"></i>
              </div>
              <div>
                <span class="text-muted small fw-semibold text-uppercase">Total SaaS Revenue</span>
                <h3 class="fw-bold mb-0 text-dark"><?= format_currency($totalRevenue) ?></h3>
                <span class="small text-success"><i class="bi bi-graph-up me-1"></i>Subscriptions & Setup</span>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm rounded-3 h-100 bg-white">
            <div class="card-body p-3 d-flex align-items-center gap-3">
              <div class="rounded-3 p-3 bg-success-subtle text-success">
                <i class="bi bi-check2-circle fs-3"></i>
              </div>
              <div>
                <span class="text-muted small fw-semibold text-uppercase">Active Licenses</span>
                <h3 class="fw-bold mb-0 text-dark"><?= $activeShopsCount ?></h3>
                <span class="small text-muted">Fully operational shops</span>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm rounded-3 h-100 bg-white">
            <div class="card-body p-3 d-flex align-items-center gap-3">
              <div class="rounded-3 p-3 bg-warning-subtle text-warning">
                <i class="bi bi-hourglass-split fs-3"></i>
              </div>
              <div>
                <span class="text-muted small fw-semibold text-uppercase">Expiring (7 Days)</span>
                <h3 class="fw-bold mb-0 text-warning"><?= $expiringShopsCount ?></h3>
                <span class="small text-muted">Renewal notices sent</span>
              </div>
            </div>
          </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3">
          <div class="card border-0 shadow-sm rounded-3 h-100 bg-white">
            <div class="card-body p-3 d-flex align-items-center gap-3">
              <div class="rounded-3 p-3 bg-danger-subtle text-danger">
                <i class="bi bi-slash-circle fs-3"></i>
              </div>
              <div>
                <span class="text-muted small fw-semibold text-uppercase">Expired / Suspended</span>
                <h3 class="fw-bold mb-0 text-danger"><?= $expiredShopsCount ?></h3>
                <span class="small text-muted">Counter blocked</span>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Subscription Plans Section -->
      <div class="card border-0 shadow-sm rounded-3 mb-4 bg-white">
        <div class="card-header bg-white border-bottom py-3 d-flex align-items-center justify-content-between">
          <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-tags-fill me-2 text-primary"></i>Subscription Plans & Pricing</h5>
          <span class="small text-muted">Offered to physical printing shops</span>
        </div>
        <div class="card-body p-4">
          <div class="row g-4">
            <?php foreach ($plans as $plan): ?>
              <div class="col-12 col-md-6 col-lg-4">
                <div class="card h-100 border rounded-3 p-4 position-relative <?= $plan['is_default'] ? 'border-primary shadow-sm' : '' ?>">
                  <?php if ($plan['is_default']): ?>
                    <span class="position-absolute top-0 start-50 translate-middle badge rounded-pill bg-primary px-3 py-1">
                      DEFAULT RECURRING PLAN
                    </span>
                  <?php endif; ?>
                  <div class="d-flex justify-content-between align-items-start mb-2">
                    <h5 class="fw-bold text-dark mb-0"><?= e($plan['name']) ?></h5>
                    <span class="badge bg-<?= $plan['status'] === 'active' ? 'success' : 'secondary' ?>-subtle text-<?= $plan['status'] === 'active' ? 'success' : 'secondary' ?>">
                      <?= ucfirst($plan['status']) ?>
                    </span>
                  </div>
                  <div class="mb-3">
                    <span class="fs-2 fw-bold text-primary"><?= format_currency($plan['price']) ?></span>
                    <span class="text-muted">/ <?= $plan['duration_months'] ?> months</span>
                  </div>
                  <div class="small text-muted mb-3">
                    <div><strong>Setup Fee:</strong> <?= format_currency($plan['setup_fee']) ?> (One-time)</div>
                    <div><strong>Duration:</strong> <?= $plan['duration_months'] ?> Months (~<?= $plan['duration_months'] * 30 ?> Days)</div>
                  </div>
                  <p class="small text-secondary flex-grow-1"><?= e($plan['description']) ?></p>
                  <button type="button" class="btn btn-outline-primary btn-sm w-100" 
                          data-bs-toggle="modal" data-bs-target="#modalEditPlan<?= $plan['id'] ?>">
                    <i class="bi bi-pencil-square me-1"></i> Edit Plan & Pricing
                  </button>
                </div>
              </div>

              <!-- Edit Plan Modal -->
              <div class="modal fade" id="modalEditPlan<?= $plan['id'] ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog">
                  <div class="modal-content border-0 shadow">
                    <form method="POST">
                      <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
                      <input type="hidden" name="action" value="save_plan">
                      <input type="hidden" name="plan_id" value="<?= $plan['id'] ?>">
                      <div class="modal-header">
                        <h5 class="modal-title fw-bold">Edit Plan: <?= e($plan['name']) ?></h5>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                      </div>
                      <div class="modal-body">
                        <div class="mb-3">
                          <label class="form-label small fw-bold">Plan Name</label>
                          <input type="text" name="name" class="form-control" value="<?= e($plan['name']) ?>" required>
                        </div>
                        <div class="row g-2 mb-3">
                          <div class="col-6">
                            <label class="form-label small fw-bold">Duration (Months)</label>
                            <input type="number" name="duration_months" class="form-control" min="1" max="36" value="<?= $plan['duration_months'] ?>" required>
                          </div>
                          <div class="col-6">
                            <label class="form-label small fw-bold">Subscription Price (₹)</label>
                            <input type="number" step="0.01" name="price" class="form-control" value="<?= $plan['price'] ?>" required>
                          </div>
                        </div>
                        <div class="mb-3">
                          <label class="form-label small fw-bold">One-Time Setup Fee (₹)</label>
                          <input type="number" step="0.01" name="setup_fee" class="form-control" value="<?= $plan['setup_fee'] ?>" required>
                          <span class="form-text small">Charged only upon onboarding a new print shop.</span>
                        </div>
                        <div class="mb-3">
                          <label class="form-label small fw-bold">Description / Feature Summary</label>
                          <textarea name="description" class="form-control" rows="3"><?= e($plan['description']) ?></textarea>
                        </div>
                        <div class="mb-3">
                          <div class="form-check form-switch">
                            <input class="form-check-input" type="checkbox" name="is_default" value="1" id="defCheck<?= $plan['id'] ?>" <?= $plan['is_default'] ? 'checked' : '' ?>>
                            <label class="form-check-label small fw-bold" for="defCheck<?= $plan['id'] ?>">Set as Default 3-Month Plan</label>
                          </div>
                        </div>
                        <div class="mb-3">
                          <label class="form-label small fw-bold">Plan Status</label>
                          <select name="status" class="form-select">
                            <option value="active" <?= $plan['status'] === 'active' ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $plan['status'] === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                          </select>
                        </div>
                      </div>
                      <div class="modal-footer bg-light">
                        <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm px-4">Save Changes</button>
                      </div>
                    </form>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

      <!-- Shop Licenses Table -->
      <div class="card border-0 shadow-sm rounded-3 mb-4 bg-white">
        <div class="card-header bg-white border-bottom py-3 d-flex align-items-center justify-content-between">
          <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-shop me-2 text-primary"></i>Shop Licenses & Expiry Overview</h5>
          <span class="badge bg-light text-dark border"><?= count($shops) ?> Total Shops</span>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light small text-uppercase">
              <tr>
                <th class="ps-4">Shop</th>
                <th>Current Plan</th>
                <th>Setup Fee</th>
                <th>Valid Until</th>
                <th>Status / Days Left</th>
                <th class="text-end pe-4">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($shops)): ?>
                <tr>
                  <td colspan="6" class="text-center py-4 text-muted">No print shops registered yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($shops as $s): 
                  $days = (int)($s['days_left'] ?? 0);
                  $isExpired = empty($s['subscription_expires_at']) || strtotime($s['subscription_expires_at']) < time();
                  $isExpiring = !$isExpired && $days <= 7;
                ?>
                  <tr>
                    <td class="ps-4">
                      <div class="fw-bold text-dark"><?= e($s['name']) ?></div>
                      <div class="small text-muted">Slug: <code><?= e($s['slug']) ?></code> • <?= e($s['phone']) ?></div>
                    </td>
                    <td>
                      <span class="badge bg-light text-dark border"><?= e($s['plan_name'] ?? '3-Month Quarterly Pro') ?></span>
                    </td>
                    <td>
                      <?php if ($s['setup_fee_paid']): ?>
                        <span class="badge bg-success-subtle text-success"><i class="bi bi-check-circle me-1"></i>Paid</span>
                      <?php else: ?>
                        <span class="badge bg-warning-subtle text-warning"><i class="bi bi-clock me-1"></i>Unpaid</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if (!empty($s['subscription_expires_at'])): ?>
                        <span class="fw-semibold"><?= date('d M Y', strtotime($s['subscription_expires_at'])) ?></span>
                      <?php else: ?>
                        <span class="text-muted">Not Configured</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <?php if ($isExpired): ?>
                        <span class="badge bg-danger-subtle text-danger border border-danger-subtle px-2 py-1">
                          <i class="bi bi-x-circle me-1"></i>Expired (<?= abs($days) ?>d ago)
                        </span>
                      <?php elseif ($isExpiring): ?>
                        <span class="badge bg-warning-subtle text-warning border border-warning-subtle px-2 py-1">
                          <i class="bi bi-hourglass-split me-1"></i>Expiring in <?= $days ?> days
                        </span>
                      <?php else: ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle px-2 py-1">
                          <i class="bi bi-check-circle me-1"></i>Active (<?= $days ?> days left)
                        </span>
                      <?php endif; ?>
                    </td>
                    <td class="text-end pe-4">
                      <div class="d-inline-flex gap-1">
                        <button type="button" class="btn btn-sm btn-outline-success" 
                                onclick="openExtendModal(<?= $s['id'] ?>, '<?= addslashes($s['name']) ?>', '<?= !empty($s['subscription_expires_at']) ? date('Y-m-d', strtotime($s['subscription_expires_at'])) : '' ?>')">
                          <i class="bi bi-clock-history me-1"></i> Extend
                        </button>
                        <a href="<?= APP_URL ?>/admin/shop-view.php?id=<?= $s['id'] ?>" class="btn btn-sm btn-outline-primary">
                          <i class="bi bi-eye"></i>
                        </a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Subscription Payment History (Ledger) -->
      <div class="card border-0 shadow-sm rounded-3 bg-white">
        <div class="card-header bg-white border-bottom py-3 d-flex align-items-center justify-content-between">
          <h5 class="fw-bold mb-0 text-dark"><i class="bi bi-journal-text me-2 text-primary"></i>Subscription Payment Ledger</h5>
          <span class="small text-muted">Latest 50 transactions</span>
        </div>
        <div class="table-responsive">
          <table class="table table-hover align-middle mb-0">
            <thead class="table-light small text-uppercase">
              <tr>
                <th class="ps-4">ID</th>
                <th>Shop</th>
                <th>Amount</th>
                <th>Payment Method</th>
                <th>Validity Period</th>
                <th>Reference / Notes</th>
                <th class="pe-4 text-end">Date</th>
              </tr>
            </thead>
            <tbody>
              <?php if (empty($ledger)): ?>
                <tr>
                  <td colspan="7" class="text-center py-4 text-muted">No subscription payments recorded yet.</td>
                </tr>
              <?php else: ?>
                <?php foreach ($ledger as $tx): ?>
                  <tr>
                    <td class="ps-4 fw-bold">#SUB-<?= $tx['id'] ?></td>
                    <td>
                      <span class="fw-bold text-dark"><?= e($tx['shop_name']) ?></span>
                    </td>
                    <td><span class="fw-bold text-success"><?= format_currency($tx['amount']) ?></span></td>
                    <td>
                      <?php if ($tx['payment_method'] === 'razorpay'): ?>
                        <span class="badge bg-primary-subtle text-primary border"><i class="bi bi-lightning-charge-fill me-1"></i>Razorpay</span>
                      <?php elseif ($tx['payment_method'] === 'upi'): ?>
                        <span class="badge bg-info-subtle text-info border"><i class="bi bi-qr-code me-1"></i>UPI Transfer</span>
                      <?php elseif ($tx['payment_method'] === 'cash'): ?>
                        <span class="badge bg-secondary-subtle text-secondary border"><i class="bi bi-cash me-1"></i>Cash</span>
                      <?php else: ?>
                        <span class="badge bg-light text-dark border">Manual Admin</span>
                      <?php endif; ?>
                    </td>
                    <td>
                      <span class="small"><?= date('d M Y', strtotime($tx['starts_at'])) ?> → <strong><?= date('d M Y', strtotime($tx['expires_at'])) ?></strong></span>
                    </td>
                    <td>
                      <span class="small text-muted"><?= e($tx['razorpay_payment_id'] ?? $tx['notes'] ?? '-') ?></span>
                    </td>
                    <td class="pe-4 text-end small text-muted">
                      <?= date('d M Y, h:i A', strtotime($tx['created_at'])) ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>

    </div>
  </main>
</div>

<!-- Modal: Add Subscription Plan -->
<div class="modal fade" id="modalAddPlan" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
        <input type="hidden" name="action" value="save_plan">
        <div class="modal-header">
          <h5 class="modal-title fw-bold">Create New Subscription Plan</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label small fw-bold">Plan Name</label>
            <input type="text" name="name" class="form-control" placeholder="e.g. 6-Month Semi-Annual Plan" required>
          </div>
          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label small fw-bold">Duration (Months)</label>
              <input type="number" name="duration_months" class="form-control" min="1" max="36" value="3" required>
            </div>
            <div class="col-6">
              <label class="form-label small fw-bold">Subscription Price (₹)</label>
              <input type="number" step="0.01" name="price" class="form-control" placeholder="1499.00" required>
            </div>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-bold">Setup Fee (₹)</label>
            <input type="number" step="0.01" name="setup_fee" class="form-control" value="1999.00" required>
          </div>
          <div class="mb-3">
            <label class="form-label small fw-bold">Description</label>
            <textarea name="description" class="form-control" rows="3" placeholder="Plan details and features"></textarea>
          </div>
          <div class="mb-3">
            <div class="form-check form-switch">
              <input class="form-check-input" type="checkbox" name="is_default" value="1" id="addDefCheck">
              <label class="form-check-label small fw-bold" for="addDefCheck">Make Default Plan</label>
            </div>
          </div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-primary btn-sm px-4">Create Plan</button>
        </div>
      </form>
    </div>
  </div>
</div>

<!-- Modal: Manual License Extender -->
<div class="modal fade" id="modalExtendLicense" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog">
    <div class="modal-content border-0 shadow">
      <form method="POST">
        <input type="hidden" name="csrf_token" value="<?= get_csrf_token() ?>">
        <input type="hidden" name="action" value="extend_license">
        <div class="modal-header">
          <h5 class="modal-title fw-bold"><i class="bi bi-clock-history me-2 text-success"></i>Manual License Grant / Extension</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label small fw-bold">Select Print Shop</label>
            <select name="shop_id" id="extendShopSelect" class="form-select" required>
              <option value="">-- Choose Shop --</option>
              <?php foreach ($shops as $s): ?>
                <option value="<?= $s['id'] ?>"><?= e($s['name']) ?> (<?= e($s['slug']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-bold">Duration Extension</label>
            <div class="d-flex gap-2 mb-2">
              <button type="button" class="btn btn-outline-primary btn-sm flex-fill" onclick="setExtensionDays(90)">+3 Months (90d)</button>
              <button type="button" class="btn btn-outline-primary btn-sm flex-fill" onclick="setExtensionDays(180)">+6 Months (180d)</button>
              <button type="button" class="btn btn-outline-primary btn-sm flex-fill" onclick="setExtensionDays(365)">+1 Year (365d)</button>
            </div>
            <input type="number" name="days_to_add" id="extendDaysInput" class="form-control" value="90" min="1" required>
            <span class="form-text small">Days added to the shop's current expiry date (or from today if expired).</span>
          </div>

          <div class="row g-2 mb-3">
            <div class="col-6">
              <label class="form-label small fw-bold">Amount Paid (₹)</label>
              <input type="number" step="0.01" name="amount_paid" class="form-control" value="1499.00">
            </div>
            <div class="col-6">
              <label class="form-label small fw-bold">Payment Method</label>
              <select name="payment_method" class="form-select">
                <option value="upi">UPI / GPay / PhonePe</option>
                <option value="cash">Cash in Hand</option>
                <option value="manual_offline">Bank Transfer (NEFT/IMPS)</option>
                <option value="razorpay">Razorpay Direct</option>
              </select>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label small fw-bold">Payment Reference / Note</label>
            <input type="text" name="notes" class="form-control" placeholder="e.g. Paid via GPay Ref #123456789">
          </div>
        </div>
        <div class="modal-footer bg-light">
          <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">Cancel</button>
          <button type="submit" class="btn btn-success btn-sm px-4">Grant & Update License</button>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
function openExtendModal(shopId, shopName, currentExpiry) {
  const modal = new bootstrap.Modal(document.getElementById('modalExtendLicense'));
  document.getElementById('extendShopSelect').value = shopId;
  modal.show();
}

function setExtensionDays(days) {
  document.getElementById('extendDaysInput').value = days;
}
</script>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
