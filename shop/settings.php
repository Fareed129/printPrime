<?php
/**
 * PrimePrint Shop - Shop Settings & Profile
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

// Fetch current shop details
$stmt = $db->prepare("SELECT * FROM shops WHERE id = :id LIMIT 1");
$stmt->execute([':id' => $shopId]);
$shop = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf_token();

    $ownerName = trim($_POST['owner_name'] ?? '');
    $phone     = trim($_POST['phone'] ?? '');
    $email     = trim($_POST['email'] ?? '');
    $address   = trim($_POST['address'] ?? '');

    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';

    if (empty($ownerName)) $errors[] = 'Owner name is required.';
    if (empty($phone))     $errors[] = 'Phone number is required.';
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Valid email is required.';
    }

    if (!empty($newPassword)) {
        if (strlen($newPassword) < 6) {
            $errors[] = 'New password must be at least 6 characters.';
        }
        if ($newPassword !== $confirmPassword) {
            $errors[] = 'Password confirmation does not match.';
        }
    }

    if (empty($errors)) {
        try {
            // Update Shop Info
            $stmt = $db->prepare("
                UPDATE shops 
                SET owner_name = :owner_name, phone = :phone, email = :email, address = :address 
                WHERE id = :id
            ");
            $stmt->execute([
                ':owner_name' => $ownerName,
                ':phone'      => $phone,
                ':email'      => $email,
                ':address'    => $address,
                ':id'         => $shopId
            ]);

            // Update Password if provided
            if (!empty($newPassword)) {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $stmt = $db->prepare("UPDATE users SET password_hash = :hash WHERE id = :id");
                $stmt->execute([':hash' => $hash, ':id' => $shopUser['id']]);
            }

            flash_set('success', 'Shop settings updated successfully.');
            header("Location: " . APP_URL . "/shop/settings.php");
            exit;

        } catch (Exception $e) {
            $errors[] = 'Failed to update settings: ' . $e->getMessage();
        }
    }
}

$customerUrl = APP_URL . "/p/" . $shop['slug'];
$pageTitle = 'Shop Settings — ' . APP_NAME;
require_once __DIR__ . '/../includes/header.php';
?>

<div class="mb-4">
  <h3 class="fw-bold text-dark mb-1">Shop Settings</h3>
  <p class="text-muted small mb-0">Manage your printing shop contact details, physical address, and login password.</p>
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

<div class="row g-4">
  
  <div class="col-lg-7">
    <div class="card card-pp">
      <div class="card-pp-header">
        <h5 class="card-pp-title"><i class="bi bi-shop me-2 text-primary"></i>Shop Profile & Contact</h5>
      </div>
      <div class="card-body p-4">
        
        <form method="POST" action="<?= APP_URL ?>/shop/settings.php">
          <?= csrf_field() ?>

          <div class="mb-3">
            <label class="form-label fw-semibold text-secondary">Shop Name</label>
            <input type="text" class="form-control bg-light" value="<?= e($shop['name']) ?>" disabled>
            <div class="form-text small">Contact Super Admin if you need to alter the registered shop name or URL slug.</div>
          </div>

          <div class="row g-3 mb-3">
            <div class="col-md-6">
              <label class="form-label fw-semibold text-secondary">Owner / Manager Name <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="owner_name" value="<?= e($_POST['owner_name'] ?? $shop['owner_name']) ?>" required>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold text-secondary">Contact Phone <span class="text-danger">*</span></label>
              <input type="text" class="form-control" name="phone" value="<?= e($_POST['phone'] ?? $shop['phone']) ?>" required>
            </div>
          </div>

          <div class="mb-3">
            <label class="form-label fw-semibold text-secondary">Public Email Address <span class="text-danger">*</span></label>
            <input type="email" class="form-control" name="email" value="<?= e($_POST['email'] ?? $shop['email']) ?>" required>
          </div>

          <div class="mb-4">
            <label class="form-label fw-semibold text-secondary">Physical Address</label>
            <textarea class="form-control" name="address" rows="3"><?= e($_POST['address'] ?? $shop['address']) ?></textarea>
          </div>

          <hr class="my-4">
          <h6 class="fw-bold text-dark mb-2">Change Account Password</h6>
          <p class="small text-muted mb-3">Leave blank if you do not want to change your password.</p>

          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label fw-semibold text-secondary">New Password</label>
              <input type="password" class="form-control" name="new_password" placeholder="Minimum 6 characters">
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold text-secondary">Confirm New Password</label>
              <input type="password" class="form-control" name="confirm_password" placeholder="Re-type new password">
            </div>
          </div>

          <button type="submit" class="btn btn-primary px-4 fw-semibold">
            <i class="bi bi-check2-circle me-1"></i> Save Changes
          </button>
        </form>

      </div>
    </div>
  </div>

  <div class="col-lg-5">
    <div class="card card-pp mb-4">
      <div class="card-pp-header">
        <h5 class="card-pp-title"><i class="bi bi-qr-code me-2 text-primary"></i>Customer Direct URL</h5>
      </div>
      <div class="card-body p-3">
        <p class="small text-muted mb-2">Your customers access this mobile-first portal to upload and print documents.</p>
        <div class="p-2 bg-light rounded border font-monospace small text-break mb-3">
          <?= $customerUrl ?>
        </div>
        <div class="d-flex gap-2">
          <button class="btn btn-outline-secondary btn-sm flex-fill" onclick="copyToClipboard('<?= $customerUrl ?>', this)">
            <i class="bi bi-clipboard me-1"></i> Copy URL
          </button>
          <a href="<?= $customerUrl ?>" target="_blank" class="btn btn-primary btn-sm flex-fill">
            <i class="bi bi-box-arrow-up-right me-1"></i> Open Portal
          </a>
        </div>
      </div>
    </div>

    <div class="card card-pp bg-light">
      <div class="card-body p-3 small text-muted">
        <div class="fw-bold text-dark mb-1"><i class="bi bi-shield-check text-success me-1"></i>Multi-Tenant Security Isolation</div>
        <div>Your shop data, print jobs, pricing, and hardware configurations are strictly isolated and encrypted to your tenant ID (<code>#<?= $shopId ?></code>).</div>
      </div>
    </div>
  </div>

</div>

<?php require_once __DIR__ . '/../includes/footer.php'; ?>
