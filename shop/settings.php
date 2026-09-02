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

    // Razorpay Credentials
    $razorpayKeyId     = trim($_POST['razorpay_key_id'] ?? '');
    $razorpayKeySecret = trim($_POST['razorpay_key_secret'] ?? '');

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
        // Check if new email is already registered by another account
        $checkStmt = $db->prepare("SELECT id FROM users WHERE LOWER(email) = LOWER(:email) AND id != :id LIMIT 1");
        $checkStmt->execute([':email' => $email, ':id' => $shopUser['id']]);
        if ($checkStmt->fetch()) {
            $errors[] = 'This email address is already in use by another user account.';
        }
    }

    if (empty($errors)) {
        try {
            // Update Shop Info & Razorpay Keys
            $stmt = $db->prepare("
                UPDATE shops 
                SET owner_name = :owner_name, 
                    phone = :phone, 
                    email = :email, 
                    address = :address,
                    razorpay_key_id = :rzp_key,
                    razorpay_key_secret = :rzp_secret
                WHERE id = :id
            ");
            $stmt->execute([
                ':owner_name'  => $ownerName,
                ':phone'       => $phone,
                ':email'       => $email,
                ':address'     => $address,
                ':rzp_key'     => !empty($razorpayKeyId) ? $razorpayKeyId : null,
                ':rzp_secret'  => !empty($razorpayKeySecret) ? $razorpayKeySecret : null,
                ':id'          => $shopId
            ]);

            // Synchronize User Account (email, name, and optional new password)
            if (!empty($newPassword)) {
                $hash = password_hash($newPassword, PASSWORD_BCRYPT);
                $stmt = $db->prepare("UPDATE users SET email = :email, name = :name, password_hash = :hash WHERE id = :id");
                $stmt->execute([':email' => $email, ':name' => $ownerName, ':hash' => $hash, ':id' => $shopUser['id']]);
            } else {
                $stmt = $db->prepare("UPDATE users SET email = :email, name = :name WHERE id = :id");
                $stmt->execute([':email' => $email, ':name' => $ownerName, ':id' => $shopUser['id']]);
            }

            // Sync current session so user doesn't get logged out or desynced
            $_SESSION['user']['email'] = $email;
            $_SESSION['user']['name'] = $ownerName;

            flash_set('success', 'Shop profile and payment gateway settings updated successfully.');
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
  <p class="text-muted small mb-0">Manage your printing shop contact details, physical address, Razorpay gateway, and login password.</p>
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
    <div class="card card-pp mb-4">
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
            <label class="form-label fw-semibold text-secondary">Email Address (Login & Contact) <span class="text-danger">*</span></label>
            <input type="email" class="form-control" name="email" value="<?= e($_POST['email'] ?? $shop['email']) ?>" required>
            <div class="form-text small text-muted">This email is used as your login email to access this Shop Portal and for customer receipts.</div>
          </div>


          <div class="mb-4">
            <label class="form-label fw-semibold text-secondary">Physical Address</label>
            <textarea class="form-control" name="address" rows="3"><?= e($_POST['address'] ?? $shop['address']) ?></textarea>
          </div>

          <hr class="my-4">

          <!-- Direct Razorpay Settings -->
          <div class="d-flex align-items-center justify-content-between mb-2">
            <h6 class="fw-bold text-dark mb-0">
              <i class="bi bi-credit-card-2-front-fill text-primary me-2"></i>Direct Razorpay Gateway (Walk-in Customer Settlements)
            </h6>
            <?php if (!empty($shop['razorpay_key_id'])): ?>
              <span class="badge bg-success-subtle text-success border"><i class="bi bi-check-circle me-1"></i>Direct Bank Active</span>
            <?php else: ?>
              <span class="badge bg-light text-muted border">Using Platform Default</span>
            <?php endif; ?>
          </div>
          <p class="small text-muted mb-3">
            Enter your shop's Razorpay API keys below so customer payments from your counter QR go <strong>directly and instantly into your bank account</strong>.
          </p>

          <div class="row g-3 mb-4">
            <div class="col-md-6">
              <label class="form-label fw-semibold text-secondary small">Razorpay Key ID</label>
              <input type="text" class="form-control font-monospace" name="razorpay_key_id" 
                     value="<?= e($_POST['razorpay_key_id'] ?? $shop['razorpay_key_id'] ?? '') ?>" 
                     placeholder="rzp_live_...">
              <span class="form-text small text-muted">Found in Razorpay Dashboard → Settings → API Keys.</span>
            </div>
            <div class="col-md-6">
              <label class="form-label fw-semibold text-secondary small">Razorpay Key Secret</label>
              <input type="password" class="form-control font-monospace" name="razorpay_key_secret" 
                     value="<?= e($_POST['razorpay_key_secret'] ?? $shop['razorpay_key_secret'] ?? '') ?>" 
                     placeholder="••••••••••••••••">
              <span class="form-text small text-muted">Used to sign & verify counter payments safely.</span>
            </div>
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
